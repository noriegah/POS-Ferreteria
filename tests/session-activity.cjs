const fs=require('fs'),vm=require('vm'),assert=require('assert');
const source=fs.readFileSync(require('path').join(__dirname,'../frontend/js/session-activity.js'),'utf8');
function fixture(){
 let now=0,next=1;const timers=new Map(),events={};const applied=[],expired=[];let renewals=0,renew=async()=>({access_token:'renewed-'+(++renewals),expires_in:900});
 const context={Date:{now:()=>now},Number,Error,document:{addEventListener:(event,callback)=>events[event]=callback},window:{addEventListener:(event,callback)=>events[event]=callback},setTimeout:(fn,ms)=>{const id=next++;timers.set(id,{fn,at:now+ms});return id},clearTimeout:id=>timers.delete(id)};
 vm.createContext(context);vm.runInContext(source+';globalThis.session=SessionActivity;',context);
 const session=context.session;session.init({renew:()=>renew(),apply:t=>applied.push(t),onExpire:r=>expired.push(r)});
 const flush=async()=>{for(let i=0;i<12;i++)await Promise.resolve()};
 return {session,applied,expired,events,get renewals(){return renewals},setRenew:fn=>renew=fn,setNow:t=>now=t,click:()=>events.click({isTrusted:true}),flush,
 async advance(ms){const end=now+ms;for(let n=0;n<1000;n++){let pair=[...timers].filter(([,t])=>t.at<=end).sort((a,b)=>a[1].at-b[1].at)[0];if(!pair)break;now=pair[1].at;timers.delete(pair[0]);pair[1].fn();await flush();}now=end;await flush();}};
}
(async()=>{
 // La renovación automática no extiende el límite de inactividad.
 let f=fixture();f.session.start(900);await f.advance(899999);assert.equal(f.expired.length,0);assert(f.renewals>0);await f.advance(1);assert.equal(f.expired.length,1);
 // Un clic justo antes del límite concede otros quince minutos.
 f=fixture();f.session.start(900);await f.advance(899000);f.click();await f.advance(899999);assert.equal(f.expired.length,0);await f.advance(1);assert.equal(f.expired.length,1);
 // Una jornada activa supera varias veces el vencimiento original del JWT.
 f=fixture();f.session.start(900);for(let n=0;n<12;n++){await f.advance(300000);f.click();}assert.equal(f.expired.length,0);assert(f.applied.length>=4);
 // Volver de suspensión tras el límite no resucita la sesión.
 f=fixture();f.session.start(900);f.setNow(900001);f.click();assert.equal(f.expired.length,1);
 // Eventos generados por código no mantienen una sesión abierta.
 f=fixture();f.session.start(900);await f.advance(600000);f.events.click({isTrusted:false});await f.advance(300000);assert.equal(f.expired.length,1);
 // Las renovaciones simultáneas se agrupan y no afectan una sesión posterior.
 f=fixture();let resolve;f.setRenew(()=>new Promise(r=>resolve=r));f.session.start(900);f.setNow(850000);const a=f.session.ensure(),b=f.session.ensure();f.session.stop();f.session.start(900);resolve({access_token:'old-session',expires_in:900});await Promise.all([a,b]);assert.equal(f.applied.length,0);
 // Una cuenta revocada se desconecta al intentar renovar.
 f=fixture();f.setRenew(async()=>{const error=new Error('Revoked');error.status=401;throw error});f.session.start(900);await f.advance(840000);assert.equal(f.expired.length,1);
 // Un fallo de red transitorio reintenta sin contar como interacción.
 f=fixture();let attempts=0;f.setRenew(async()=>{if(++attempts===1)throw Error('Offline');return {access_token:'recovered',expires_in:900}});f.session.start(900);await f.advance(845000);assert.equal(f.applied.length,1);await f.advance(55000);assert.equal(f.expired.length,1);
 console.log('PASS: inactividad exacta, clic antes del límite, actividad durante una hora, suspensión, eventos sintéticos, renovación concurrente, revocación y reconexión.');
})().catch(error=>{console.error(error);process.exit(1)});
