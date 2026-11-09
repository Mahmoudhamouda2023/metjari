'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/notifications.js'), 'utf8');

function harness(options = {}) {
    class Element {
        constructor() { this.listeners = {}; this.childNodes = []; this.dataset = {}; this.hidden = false; this.textContent = ''; }
        addEventListener(name, action) { this.listeners[name] = action; }
        setAttribute(name, value) { this[name] = value; }
        replaceChildren(...nodes) { this.childNodes = nodes; }
        append(node) { this.childNodes.push(node); }
        contains() { return false; }
        matches() { return false; }
    }
    const elements = Object.fromEntries(['status', 'sound', 'count', 'items', 'read', 'toast'].map(key => [key, new Element()]));
    const root = new Element(); root.dataset = {url:'https://metjari.test/admin/notifications',readUrl:'https://metjari.test/admin/notifications/read',user:options.user || '7',identityScope:options.scope || 'staff',csrf:'csrf'};
    const selectors = {'[data-live-status]':'status','[data-notification-sound]':'sound','[data-notification-count]':'count','[data-notification-items]':'items','[data-read-notifications]':'read','[data-live-toast]':'toast'};
    root.querySelector = selector => elements[selectors[selector]];
    const region = new Element(); region.dataset.liveRegion='orders';
    const document = { hidden:false, activeElement:null, listeners:{}, querySelector:s=>s==='[data-notifications]'?root:region, querySelectorAll:()=>[region], createElement:()=>new Element(), addEventListener(name,f){this.listeners[name]=f;} };
    let data = {latest:1,version:'1',unread:1,items:[{id:1,title:'متجر الاختبار #1',url:'https://metjari.test/admin/orders/one'}]};
    let statusCode=200, requestCount=0, oscillatorCount=0, readOk=true, readResponder=null, pollResponder=null; const navigations=[];
    const audioContexts=[];
    const requests=[], timers=new Map(); let sequence=0;
    class AudioContext {
        constructor() { this.currentTime=0; this.state=(options.blocked || options.delayedResume) ? 'suspended' : 'running'; this.listeners={}; audioContexts.push(this); }
        addEventListener(name, fn) { this.listeners[name]=fn; }
        async resume() { if (options.blocked) return new Promise(()=>{}); if(options.delayedResume) { await Promise.resolve(); this.state='running'; this.listeners.statechange?.(); } }
        createOscillator(){oscillatorCount++;return {frequency:{},connect(){},start(){},stop(){}};}
        createGain(){return {gain:{setValueAtTime(){},linearRampToValueAtTime(){},exponentialRampToValueAtTime(){}},connect(){}};}
    }
    const window = {AudioContext,getSelection:()=>({toString:()=>''}),listeners:{},addEventListener(name,f){this.listeners[name]=f;}};
    const storage=options.storage || new Map();
    const context = { document,window,URL,AbortController, location:{href:'https://metjari.test/admin/orders?q=test',origin:'https://metjari.test',assign:url=>navigations.push(url)},localStorage:{getItem:k=>storage.get(k)??null,setItem:(k,v)=>storage.set(k,v)},
        setTimeout:(f,delay)=>{const id=++sequence;timers.set(id,{f,delay});return id;},clearTimeout:id=>timers.delete(id),
        fetch:async(url,options={})=>{requests.push({url,options}); if(url===root.dataset.url) return pollResponder ? pollResponder() : {status:statusCode,ok:statusCode===200,redirected:false,json:async()=>data}; if(url===root.dataset.readUrl) return readResponder ? readResponder(JSON.parse(options.body)) : {ok:readOk,redirected:false,json:async()=>({ok:readOk})}; requestCount++;return {ok:true,redirected:false,text:async()=>'<table>fresh</table>'};},
        DOMParser:class{parseFromString(){return {querySelector:()=>({childNodes:['fresh rows']})};}}
    };
    vm.runInNewContext(source,context);
    const flush = async()=>{for(let n=0;n<12;n++)await Promise.resolve();};
    return {audioContexts,elements,document,window,region,requests,timers,flush,navigations,storage,setReadOk:v=>{readOk=v;},setReadResponder:v=>{readResponder=v;},setPollResponder:v=>{pollResponder=v;},setData:v=>{data=v;},setStatus:v=>{statusCode=v;},countRefresh:()=>requestCount,countOscillators:()=>oscillatorCount,
        async poll(){const entry=[...timers].find(([,v])=>[0,10000,30000].includes(v.delay));assert.ok(entry,'poll timer scheduled');timers.delete(entry[0]);await entry[1].f();await flush();}
    };
}

test('new notices ring by default, update rows, and preserve same-origin links', async()=>{
    const h=harness();await h.flush();assert.equal(h.elements.count.textContent,'1');assert.equal(h.countOscillators(),0);
    assert.equal(h.elements.sound['aria-pressed'],'true');
    h.setData({latest:2,version:'2',unread:2,items:[{id:2,title:'متجر جديد #2',url:'https://metjari.test/admin/orders/two'},{id:3,title:'unsafe',url:'https://external.test/'}]});
    await h.poll();assert.equal(h.countOscillators(),2);assert.equal(h.elements.count.textContent,'2');assert.equal(h.elements.items.childNodes.length,1);assert.equal(h.elements.toast.hidden,false);assert.ok(h.countRefresh()>=2);
    await h.poll();assert.equal(h.countOscillators(),2,'unchanged notice does not ring repeatedly');
});

test('filter editing delays row refresh until blur without discarding the new version',async()=>{
    const h=harness();await h.flush();const initial=h.countRefresh();
    h.document.activeElement={matches:()=>true};h.setData({latest:2,version:'2',unread:0,items:[]});await h.poll();assert.equal(h.countRefresh(),initial);
    h.document.activeElement=null;await h.poll();assert.equal(h.countRefresh(),initial+1);
});

test('mark read posts the cursor with CSRF and expired sessions stop polling',async()=>{
    const h=harness();await h.flush();await h.elements.read.listeners.click();await h.flush();
    const request=h.requests.find(x=>x.options.method==='POST');assert.equal(request.options.headers['X-CSRF-TOKEN'],'csrf');assert.equal(JSON.parse(request.options.body).through,1);
    h.setStatus(401);await h.poll();assert.match(h.elements.status.textContent,/انتهت الجلسة/);assert.equal([...h.timers.values()].filter(x=>[10000,30000].includes(x.delay)).length,0);
});

test('temporary connection failure retries and recovers instead of requiring manual reload',async()=>{
    const h=harness();await h.flush();h.setStatus(500);await h.poll();assert.match(h.elements.status.textContent,/الاتصال متقطع/);
    h.setStatus(200);await h.poll();assert.match(h.elements.status.textContent,/متصل/);
});


function clickEvent(extra={}) {
    return {button:0,ctrlKey:false,metaKey:false,shiftKey:false,altKey:false,defaultPrevented:false,
        preventDefault(){this.defaultPrevented=true;},...extra};
}
function response(ok=true) { return {ok,redirected:false,json:async()=>({ok})}; }
function twoNotices() { return {latest:2,version:'2',unread:2,items:[
    {id:2,title:'الثاني',url:'https://metjari.test/admin/orders/two'},
    {id:1,title:'الأول',url:'https://metjari.test/admin/orders/one'},
]}; }

test('opening one notice immediately decrements only that notice, saves it, then navigates',async()=>{
    const h=harness();await h.flush();h.setData(twoNotices());await h.poll();
    const link=h.elements.items.childNodes[0], event=clickEvent();
    const promise=link.listeners.click(event);
    assert.equal(event.defaultPrevented,true);assert.equal(h.elements.count.textContent,'1');
    assert.equal(h.elements.items.childNodes.length,1);assert.equal(h.elements.items.childNodes[0].textContent,'الأول');
    assert.deepEqual(h.navigations,[],'navigation waits for persisted read');
    await promise;
    const req=h.requests.filter(x=>x.options.method==='POST').at(-1);
    assert.deepEqual(JSON.parse(req.options.body),{notification_id:2});
    assert.equal(req.options.keepalive,true);assert.equal(req.options.credentials,'same-origin');
    assert.equal(req.options.headers['X-CSRF-TOKEN'],'csrf');
    assert.deepEqual(h.navigations,['https://metjari.test/admin/orders/two']);
});

test('failed read restores the badge and link and permits retry',async()=>{
    const h=harness();await h.flush();h.setReadOk(false);
    await h.elements.items.childNodes[0].listeners.click(clickEvent());
    assert.equal(h.elements.count.textContent,'1');assert.equal(h.elements.items.childNodes.length,1);
    assert.deepEqual(h.navigations,[]);assert.match(h.elements.status.textContent,/تعذّر حفظ قراءة الإشعار/);
    h.setReadOk(true);await h.elements.items.childNodes[0].listeners.click(clickEvent());
    assert.equal(h.elements.count.textContent,'0');assert.equal(h.elements.count.hidden,true);assert.equal(h.navigations.length,1);
});

test('repeated clicks do not decrement twice or submit duplicate reads',async()=>{
    const h=harness();await h.flush();let release;
    h.setReadResponder(()=>new Promise(resolve=>{release=resolve;}));
    const link=h.elements.items.childNodes[0];const first=link.listeners.click(clickEvent());
    await link.listeners.click(clickEvent());assert.equal(h.elements.count.textContent,'0');
    assert.equal(h.requests.filter(x=>x.options.method==='POST').length,1);
    release(response());await first;
    await link.listeners.click(clickEvent());assert.equal(h.elements.count.textContent,'0');
    assert.equal(h.requests.filter(x=>x.options.method==='POST').length,1);
});

test('modified and middle clicks preserve native new-tab navigation while marking read',async()=>{
    for(const event of [clickEvent({ctrlKey:true}),clickEvent({metaKey:true}),clickEvent({button:1})]){
        const h=harness();await h.flush();const link=h.elements.items.childNodes[0];
        await (event.button===1 ? link.listeners.auxclick(event) : link.listeners.click(event));
        assert.equal(event.defaultPrevented,false);assert.equal(h.elements.count.textContent,'0');
        assert.deepEqual(h.navigations,[]);assert.equal(h.requests.filter(x=>x.options.method==='POST').length,1);
    }
});

test('two reads completing out of order do not restore an old unread count',async()=>{
    const h=harness();await h.flush();h.setData(twoNotices());await h.poll();const pending=new Map();
    h.setReadResponder(body=>new Promise(resolve=>pending.set(body.notification_id,resolve)));
    const [one,two]=h.elements.items.childNodes;
    const a=one.listeners.click(clickEvent({ctrlKey:true})), b=two.listeners.click(clickEvent({ctrlKey:true}));
    assert.equal(h.elements.count.textContent,'0');pending.get(1)(response());await b;
    assert.equal(h.elements.count.textContent,'0');pending.get(2)(response());await a;
    assert.equal(h.elements.count.textContent,'0');
});

test('a poll already in flight cannot restore a notice opened during that poll',async()=>{
    const h=harness();await h.flush();let release;
    h.setPollResponder(()=>new Promise(resolve=>{release=resolve;}));
    const pendingPoll=h.poll();await h.flush();
    await h.elements.items.childNodes[0].listeners.click(clickEvent({ctrlKey:true}));
    assert.equal(h.elements.count.textContent,'0');
    release({status:200,ok:true,redirected:false,json:async()=>({latest:1,version:'1',unread:1,items:[{id:1,title:'قديم',url:'https://metjari.test/admin/orders/one'}]})});
    await pendingPoll;assert.equal(h.elements.count.textContent,'0');assert.equal(h.elements.items.childNodes.length,0);
});

test('opening the menu or right-clicking a notice does not mark anything read',async()=>{
    const h=harness();await h.flush();const event=clickEvent({button:2});
    h.elements.items.childNodes[0].listeners.auxclick(event);await h.flush();
    assert.equal(h.elements.count.textContent,'1');assert.equal(h.requests.filter(x=>x.options.method==='POST').length,0);
});


test('explicit mute persists across page reloads until explicit unmute', async()=>{
 const storage=new Map(),h=harness({storage});await h.flush();
 assert.equal(h.elements.sound['aria-pressed'],'true');
 await h.elements.sound.listeners.click();assert.equal(h.elements.sound['aria-pressed'],'false');
 const reload=harness({storage});await reload.flush();assert.equal(reload.elements.sound['aria-pressed'],'false');
 reload.setData({latest:2,version:'2',unread:1,items:[]});await reload.poll();assert.equal(reload.countOscillators(),0);
 await reload.elements.sound.listeners.click();assert.equal(reload.elements.sound['aria-pressed'],'true');
 const again=harness({storage});await again.flush();assert.equal(again.elements.sound['aria-pressed'],'true');
});
test('muting a merchant does not mute staff with the same numeric identity',async()=>{
 const storage=new Map(),merchant=harness({storage,scope:'merchant'});await merchant.flush();await merchant.elements.sound.listeners.click();
 const staff=harness({storage,scope:'staff'});await staff.flush();assert.equal(staff.elements.sound['aria-pressed'],'true');
});
test('browser autoplay blocking preserves enabled preference and does not stall polling',async()=>{
 const h=harness({blocked:true});await h.flush();h.setData({latest:2,version:'2',unread:1,items:[]});await h.poll();
 assert.equal(h.elements.sound['aria-pressed'],'true');assert.equal(h.countOscillators(),0);await h.poll();
 assert.ok(h.requests.length>=3);
});
test('explicit storage mute is synchronized in another tab',async()=>{
 const h=harness();await h.flush();h.window.listeners.storage({key:'metjari-notification-muted-v1:staff:7',newValue:'1'});
 assert.equal(h.elements.sound['aria-pressed'],'false');h.window.listeners.storage({key:'metjari-notification-muted-v1:staff:7',newValue:'0'});
 assert.equal(h.elements.sound['aria-pressed'],'true');
});


test('unmuting rings after the browser asynchronously resumes audio',async()=>{
 const storage=new Map([['metjari-notification-muted-v1:staff:7','1']]);
 const h=harness({storage,delayedResume:true});await h.flush();
 await h.elements.sound.listeners.click();await h.flush();
 assert.equal(h.elements.sound['aria-pressed'],'true');assert.equal(h.countOscillators(),2);
});
test('blocked notification rings once after audio unlock and is discarded on mute',async()=>{
 for(const muted of [false,true]){
  const h=harness({blocked:true});await h.flush();h.setData({latest:2,version:'2',unread:1,items:[]});await h.poll();
  assert.equal(h.countOscillators(),0);
  if(muted)await h.elements.sound.listeners.click();
  const audio=h.audioContexts[0];audio.state='running';audio.listeners.statechange();await h.flush();
  assert.equal(h.countOscillators(),muted?0:2);
  audio.listeners.statechange();assert.equal(h.countOscillators(),muted?0:2);
 }
});
