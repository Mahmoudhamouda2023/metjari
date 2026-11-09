'use strict';
const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../public/assets/finance.js'), 'utf8');
function harness() {
    class Element {
        constructor() { this.listeners = {}; this.childNodes = []; this.dataset = {}; }
        addEventListener(event, fn) { this.listeners[event] = fn; }
        replaceChildren(...nodes) { this.childNodes = nodes; }
        append(node) { this.childNodes.push(node); }
        contains(node) { return this.members?.includes(node) || false; }
    }
    const feedback = new Element(), button = {}, form = new Element();
    form.action = 'https://metjari.test/admin/finance/settlements/1';
    form.closest = () => form; form.querySelectorAll = () => [button];
    const regions = ['ready','history','expenses'].map(key => { const r = new Element(); r.dataset.financeRegion = key; r.childNodes = [key + '-before']; return r; });
    regions[0].members = [form];
    const root = new Element(); root.dataset.liveUrl = 'https://metjari.test/admin/finance/live';
    root.querySelector = () => feedback; root.querySelectorAll = () => regions;
    const document = {hidden: false, activeElement: null, listeners: {}, querySelector: () => root, createElement: () => new Element(), createTextNode: text => ({textContent:text}), addEventListener(event, fn) {this.listeners[event] = fn;}};
    const window = {addEventListener() {}};
    const requests = [], timers = new Map(); let sequence = 0;
    let post = async () => ({ok:true,status:200,redirected:false,json:async()=>({message:'تم تسجيل التسوية',receipt_url:'https://metjari.test/admin/finance/receipts/settlement/1'})});
    let get = async () => ({ok:true,redirected:false,json:async()=>({html:'after'})});
    vm.runInNewContext(source, {root,document,window,URL,AbortController,FormData:class {},location:{origin:'https://metjari.test'},
        setTimeout(fn, delay) { const id=++sequence;timers.set(id,{fn,delay});return id; },clearTimeout(id){timers.delete(id);},
        fetch: async (url, options) => { requests.push({url,options}); return options.method === 'POST' ? post() : get(); },
        DOMParser:class {parseFromString(html){return {querySelector:s=>({childNodes:[s.includes('ready')?'ready-'+html:s.includes('history')?'history-'+html:'expenses-'+html]})};}}
    });
    return {root,document,regions,feedback,button,form,requests,timers,setPost:f=>post=f,setGet:f=>get=f,
        submit:()=>root.listeners.submit({target:form,preventDefault(){}}),
        message:()=>feedback.childNodes.map(n=>n.textContent).join(''),
        async flush(){for(let i=0;i<30;i++)await Promise.resolve();},
        poll:()=>[...timers.values()].find(t=>t.delay===15000).fn(),
    };
}
test('a saved settlement refreshes balance and history without page navigation',async()=>{
    const h=harness();await h.submit();
    assert.equal(h.requests.filter(r=>r.options.method==='POST').length,1);
    assert.equal(h.regions[0].childNodes[0],'ready-after');
    assert.equal(h.regions[1].childNodes[0],'history-after');
    assert.match(h.message(),/تم تسجيل/);
    assert.equal(h.button.disabled,false);
});
test('double submission during a pending write creates only one POST',async()=>{
    const h=harness();let finish;
    h.setPost(()=>new Promise(resolve=>finish=resolve));
    const pending=h.submit();await h.submit();
    assert.equal(h.requests.filter(r=>r.options.method==='POST').length,1);
    finish({ok:true,status:200,redirected:false,json:async()=>({message:'تم الحفظ'})});await pending;
});
test('an uncertain network result resyncs history without replaying the payment',async()=>{
    const h=harness();h.setPost(async()=>{throw Error('connection lost after commit');});await h.submit();
    assert.equal(h.requests.filter(r=>r.options.method==='POST').length,1);
    assert.equal(h.requests.filter(r=>!r.options.method).length,1);
    assert.match(h.message(),/راجع أحدث الكشوف/);
});
test('background refresh preserves a draft expense while updating the balance',async()=>{
    const h=harness(), draft={};h.regions[2].members=[draft];
    h.root.listeners.input({target:{form:draft}});await h.poll();
    assert.equal(h.regions[0].childNodes[0],'ready-after');
    assert.equal(h.regions[2].childNodes[0],'expenses-before');
});
test('a pre-payment response cannot restore the old balance after payment',async()=>{
    const h=harness();let finish;let calls=0;
    h.setGet(()=>++calls===1?new Promise(resolve=>finish=resolve):Promise.resolve({ok:true,redirected:false,json:async()=>({html:'after'})}));
    const polling=h.poll();await h.submit();
    finish({ok:true,redirected:false,json:async()=>({html:'stale'})});await polling;await h.flush();
    assert.equal(h.regions[0].childNodes[0],'ready-after');
    assert.equal(h.regions[1].childNodes[0],'history-after');
});
