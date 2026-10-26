"use strict";
// La actividad humana y la vigencia del JWT son relojes independientes.
const SessionActivity=(()=>{
    const IDLE_MS=15*60*1000;
    let options,active=false,lastActivity=0,expiresAt=0,generation=0;
    let idleTimer=null,refreshTimer=null,pending=null;
    function stop(){
        active=false;generation++;clearTimeout(idleTimer);clearTimeout(refreshTimer);pending=null;
    }
    function expire(reason){if(!active)return;stop();options.onExpire(reason);}
    function check(){
        if(!active)return false;
        if(Date.now()-lastActivity>=IDLE_MS){expire('Sesión cerrada por 15 minutos de inactividad.');return false;}
        return true;
    }
    function scheduleIdle(){
        clearTimeout(idleTimer);
        idleTimer=setTimeout(()=>{if(check())scheduleIdle();},Math.max(1,lastActivity+IDLE_MS-Date.now()));
    }
    function scheduleRefresh(delay){
        clearTimeout(refreshTimer);
        refreshTimer=setTimeout(()=>{ensure().catch(()=>{});},delay ?? Math.max(1000,expiresAt-Date.now()-60000));
    }
    async function ensure(){
        if(!check())throw new Error('Ingresa de nuevo para continuar.');
        if(expiresAt-Date.now()>60000)return;
        if(pending)return pending;
        const current=generation;
        pending=(async()=>{
            try {
                const data=await options.renew();
                if(current!==generation || !check())return;
                if(typeof data.access_token!=='string' || !Number.isFinite(data.expires_in) || data.expires_in<60)throw new Error('Respuesta de sesión inválida');
                options.apply(data.access_token);
                expiresAt=Date.now()+data.expires_in*1000;scheduleRefresh();
            } catch(error){
                if(current===generation && active){
                    if(error.status===401 || error.status===403 || Date.now()>=expiresAt)expire('La sesión venció. Ingresa de nuevo.');
                    else scheduleRefresh(5000);
                }
                throw error;
            } finally {if(current===generation)pending=null;}
        })();
        return pending;
    }
    function interaction(event){
        if(!event.isTrusted || !check())return;
        lastActivity=Date.now();scheduleIdle();ensure().catch(()=>{});
    }
    function init(config){
        options=config;
        for(const event of ['pointerdown','click','keydown','input','wheel','scroll','touchstart']) document.addEventListener(event,interaction,{capture:true,passive:true});
        document.addEventListener('visibilitychange',()=>{if(active && check())ensure().catch(()=>{});});
        window.addEventListener('focus',()=>{if(active && check())ensure().catch(()=>{});});
    }
    function start(ttl){stop();active=true;lastActivity=Date.now();expiresAt=lastActivity+ttl*1000;scheduleIdle();scheduleRefresh();}
    return {init,start,stop,ensure};
})();
