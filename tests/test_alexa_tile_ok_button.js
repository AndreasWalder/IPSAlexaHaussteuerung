// No external dependencies: exercise the actual Alexa-Shell OK-button finder
// against a small DOM-like fixture, including custom IP-Symcon SPA controls.
'use strict';
const fs=require('node:fs');
const vm=require('node:vm');
const assert=require('node:assert/strict');
const html=fs.readFileSync('IPSAlexaHaussteuerung/resources/html/alexa-diagnostic-shell.html','utf8');
const begin=html.indexOf('  function findTileLoginOkButton(');
const finish=html.indexOf('\n\n  function tileTryAutoLogin()',begin);
assert(begin>=0 && finish>begin, 'Tile finder should be present');
const fn=vm.runInNewContext('('+html.slice(begin,finish).trim()+')');

function element(tag,own='',attrs={},children=[]){
  const node={
    tagName:tag.toUpperCase(), nodeType:1,isConnected:true,hidden:false,
    disabled:false,parentElement:null,firstElementChild:null,nextElementSibling:null,
    own,attrs:{...attrs},children:[],style:{display:'block',visibility:'visible'},
    getAttribute(name){return Object.hasOwn(this.attrs,name)?this.attrs[name]:null;},
    get textContent(){return this.own+this.children.map(x=>x.textContent).join('');},
    get innerText(){return this.textContent;},
    closest(selector){
      let a=this.parentElement;
      while(a){
        if(a.tagName==='DIALOG' || a.getAttribute('role')==='dialog')return a;
        a=a.parentElement;
      }
      return null;
    },
    querySelectorAll(selector){
      const selectors=selector.split(',').map(x=>x.trim());
      const found=[];
      function matches(node,s){
        if(s==='button')return node.tagName==='BUTTON';
        if(s==='[role="button"]')return node.getAttribute('role')==='button';
        if(s==='input[type="submit"]')return node.tagName==='INPUT' && node.getAttribute('type')==='submit';
        if(s==='input[type="button"]')return node.tagName==='INPUT' && node.getAttribute('type')==='button';
        if(s==='button[type="submit"]')return node.tagName==='BUTTON' && node.getAttribute('type')==='submit';
        if(s==='[tabindex]')return node.getAttribute('tabindex')!==null;
        return node.tagName.toLowerCase()===s;
      }
      function walk(node){
        for(const child of node.children){
          if(selectors.some(s=>matches(child,s)))found.push(child);
          walk(child);
        }
      }
      walk(this);
      return found;
    }
  };
  node.children=children;
  node.firstElementChild=children[0]||null;
  children.forEach((ch,i)=>{
    ch.parentElement=node;
    ch.nextElementSibling=children[i+1]||null;
  });
  return node;
}
function run(candidate,formTag='form'){
  const password=element('input','',{type:'password'});
  const form=element(formTag,'',{},[password,candidate]);
  if(formTag==='form')password.form=form;
  const body=element('body','',{},[form]);
  const doc={body};
  const win={getComputedStyle(el){return el.style;}};
  return fn(doc,password,win);
}
// Exact real OK button must be found even when Alexa reports a 0x0 layout.
// Old code rejected it with rect.width<=0.
{
  const ok=element('button','OK');
  ok.getBoundingClientRect=()=>({width:0,height:0});
  const result=run(ok);
  assert.equal(result.element,ok);
  assert.equal(result.kind,'button_control');
}
// Custom DIV/inner SPAN clickable by bubbling (no native button/role).
{
  const span=element('span','OK');
  const custom=element('div','',{},[span]);
  const result=run(custom);
  assert.equal(result.element,span);
  assert.equal(result.kind,'button_text');
}
// A genuine submit control without a title should be CLICKED instead of
// calling form.requestSubmit() (which failed on the actual Echo).
{
  const submit=element('button','',{type:'submit'});
  const result=run(submit);
  assert.equal(result.element,submit);
  assert.equal(result.kind,'button_submit');
}
// Accessible custom action element can be an ARIA role=button.
{
  const custom=element('div','',{role:'button','aria-label':'Anmelden'});
  const result=run(custom);
  assert.equal(result.element,custom);
  assert.equal(result.kind,'button_control');
}
// Never confuse Abbrechen with OK; no arbitrary button may be clicked.
{
  const cancel=element('button','Abbrechen');
  const result=run(cancel);
  assert.equal(result.element,null);
  assert.equal(result.kind,'button_not_found');
}
// If multiple candidates are available, no arbitrary click is acceptable.
{
  const root=element('div','',{},[element('span','OK'),element('span','OK')]);
  const result=run(root);
  assert.equal(result.element,null);
  assert.equal(result.kind,'button_ambiguous');
}
// Ignore intentionally hidden login controls.
{
  const ok=element('button','OK');
  ok.style.display='none';
  const result=run(ok);
  assert.equal(result.element,null);
  assert.equal(result.kind,'button_not_found');
}
// Verify the Enter sequence used by the new Alexa fallback. We test the
// ACTUAL embedded function rather than only checking text in the source.
const keyStart=html.indexOf('  function tileSendEnter(win,input){');
const keyEnd=html.indexOf('\n\n  // Nach einem erfolglosen Versuch',keyStart);
assert(keyStart>=0 && keyEnd>keyStart,'Enter helper must be present');
const sendEnter=vm.runInNewContext('('+html.slice(keyStart,keyEnd).trim()+')');
{
  const events=[],focus=[];
  const keyboard={
    KeyboardEvent:class {
      constructor(type,init){this.type=type;this.key=init.key;this.code=init.code;this.bubbles=init.bubbles;}
    }
  };
  const input={
    focus(opts){focus.push(opts);},
    dispatchEvent(e){events.push(e);}
  };
  assert.equal(sendEnter(keyboard,input),true);
  assert.deepEqual(events.map(e=>e.type),['keydown','keypress','keyup']);
  assert(events.every(e=>e.key==='Enter' && e.code==='Enter' && e.bubbles));
  assert.equal(focus.length,1);
}
{
  const input={focus(){},dispatchEvent(){throw Error('should not dispatch');}};
  assert.equal(sendEnter({KeyboardEvent:null},input),false);
}
console.log('PASS: Tile Enter key sequence dispatched to password field');

console.log('PASS: Tile OK button matcher handles native/custom/hidden/ambiguous controls');
