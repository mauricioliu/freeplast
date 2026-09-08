/* Run only via browser_run_script on an owned tab. Input: manifestPath,
 * targetId, out (existing directory). No server, native store or submissions.
 * Assertion failures are returned and saved; they are not visual approval. */
const fs=require('node:fs');
const path=require('node:path');
const session=daemon.session(params.targetId);
const pages=JSON.parse(fs.readFileSync(params.manifestPath,'utf8'));
const rows=[];
async function evaluate(expression){const r=await daemon.evaluateJs(expression);if(!r.success)throw Error(JSON.stringify(r));return r.data;}
for(const page of pages){
 const nav=await session.call('Page.navigate',{url:page.url});if(!nav.success)throw Error('Navigation failed');
 let ready=false;
 for(let i=0;i<100;i++){await new Promise(r=>setTimeout(r,50));try{ready=await evaluate(`location.href===${JSON.stringify(page.url)}&&document.readyState==='complete'`);}catch{}if(ready)break;}
 if(!ready)throw Error('Replay did not load '+page.name);
 for(const width of [320,375,412,599,600,601,768,769,999,1000,1001,1024,1440]){
  await session.call('Emulation.setDeviceMetricsOverride',{width,height:915,deviceScaleFactor:1,mobile:false});
  const metrics=JSON.parse(await evaluate(`JSON.stringify((()=>{
   const checks=[];const add=(ok,label,actual)=>checks.push({ok,label,actual});
   const rect=e=>e.getBoundingClientRect();const style=e=>getComputedStyle(e);
   const tracks=e=>style(e).gridTemplateColumns.split(' ').map(parseFloat);
   document.querySelectorAll('.wc-block-cart').forEach(e=>{e.classList.toggle('is-large',innerWidth>=1000);e.classList.toggle('is-mobile',innerWidth<1000);});
   window.scrollTo(0,0);
   add(document.documentElement.scrollWidth<=document.documentElement.clientWidth,'no horizontal page overflow',document.documentElement.scrollWidth);
   for(const card of [...document.querySelectorAll('li.product-card')].slice(0,4)){
    const track=tracks(card.parentElement)[0],r=rect(card),a=card.querySelector('.add_to_cart_button');
    add(Math.abs(r.width-track)<2,'card fills its grid track',{card:r.width,track});
    if(a){const b=rect(a);add(b.width>=44&&b.height>=44&&a.scrollWidth<=a.clientWidth+1&&b.left>=r.left&&b.right<=r.right+1,'CTA fits card and remains usable',{button:b.width,card:r.width});}
   }
   const cart=document.querySelector('.wc-block-cart.wc-block-components-sidebar-layout');
   if(cart){
    const main=cart.querySelector('.wc-block-components-main'),side=cart.querySelector('.wc-block-components-sidebar'),t=tracks(cart);
    add(style(cart).display==='grid','cart stays grid after native CSS',style(cart).display);
    add(Math.abs(rect(main).width-t[0])<2,'main fills track',{main:rect(main).width,track:t[0]});
    add(Math.abs(rect(side).width-(innerWidth>=1000?340:t[0]))<2,'summary fills track',rect(side).width);
    add(parseFloat(style(side).paddingLeft)>=20,'summary retains mobile padding',style(side).paddingLeft);
    add(innerWidth>=1000?rect(side).left>=rect(main).right+40:rect(side).top>=rect(main).bottom,'summary does not overlap items',{main:rect(main).toJSON(),side:rect(side).toJSON()});
    const remove=cart.querySelector('.wc-block-cart-item__remove-link');
    add(!!remove&&rect(remove).width>=44&&rect(remove).height>=44&&parseFloat(style(remove).borderTopWidth)>=1,'remove retains bordered 44px target after late native CSS',remove&&style(remove).cssText);
   }
   const checkout=document.querySelector('.fp-checkout-page');
   if(checkout){const r=rect(checkout);add(Math.abs(r.left-(document.documentElement.clientWidth-r.right))<2,'checkout centered in viewport',{left:r.left,right:document.documentElement.clientWidth-r.right});}
   const button=document.querySelector('.single_add_to_cart_button');
   if(button){const previous=button.getAttribute('aria-disabled');button.setAttribute('aria-disabled','false');const enabled=style(button).backgroundColor;button.setAttribute('aria-disabled','true');add(style(button).backgroundColor!==enabled,'disabled add is visually distinct',{enabled,disabled:style(button).backgroundColor});if(previous===null)button.removeAttribute('aria-disabled');else button.setAttribute('aria-disabled',previous);}
   return {width:innerWidth,checks};
  })())`));
  rows.push({page:page.name,...metrics});
  if([412,1440].includes(width)){
   if(page.name==='product')await evaluate("window.scrollTo(0, Math.max(0, document.querySelector('.related').getBoundingClientRect().top + scrollY - 100))");
   await new Promise(r=>setTimeout(r,100));
   const shot=await session.call('Page.captureScreenshot',{format:'jpeg',quality:85,captureBeyondViewport:false});
   fs.writeFileSync(path.join(params.out,`${page.name}-${width}-after.jpeg`),Buffer.from(shot.data.data,'base64'));
  }
 }
}
fs.writeFileSync(path.join(params.out,'layout-results.json'),JSON.stringify(rows,null,2));
const failed=rows.flatMap(row=>row.checks.filter(c=>!c.ok).map(c=>({page:row.page,width:row.width,...c})));
return {content:[{type:'text',text:JSON.stringify({verdict:failed.length?'FAIL':'PASS',scenarios:rows.length,checks:rows.reduce((n,r)=>n+r.checks.length,0),failureCount:failed.length,failed:failed.slice(0,12)})}]};
