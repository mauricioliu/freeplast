// One-off static capture of public rendered markup. No WordPress mutations.
const fs=require('fs'),path=require('path'),crypto=require('crypto'),{load}=require('cheerio');
const root=__dirname, base='http://mliu:8131/';
fs.mkdirSync(path.join(root,'assets'),{recursive:true});
const cache=new Map();
async function asset(raw,parent=base){
 const u=new URL(raw,parent); if(!/^https?:$/.test(u.protocol))return raw;
 if(cache.has(u.href))return cache.get(u.href);
 const name=crypto.createHash('sha1').update(u.href).digest('hex').slice(0,10)+'-'+path.basename(u.pathname);
 cache.set(u.href,'assets/'+name);
 const res=await fetch(u); if(!res.ok)throw Error(u+' '+res.status);
 let data=Buffer.from(await res.arrayBuffer());
 if(u.pathname.endsWith('.css')){let css=data.toString();for(const match of [...css.matchAll(/url\(\s*['"]?([^)'"\s]+)['"]?\s*\)/g)]){if(match[1].startsWith('data:')||match[1].startsWith('#'))continue;const local=await asset(match[1],u);css=css.replace(match[0],`url("${path.basename(local)}")`);}data=Buffer.from(css);}
 fs.writeFileSync(path.join(root,'assets',name),data);return 'assets/'+name;
}
(async()=>{
 const $=load(fs.readFileSync('/tmp/freeplast-rendered.html','utf8'));
 $('script,link:not([rel="stylesheet"]):not([rel="preload"]),noscript').remove();
 $('html').attr('lang','es');$('title').text('Freeplast · PROTOTIPO visual');
 $('head').prepend('<meta http-equiv="Content-Security-Policy" content="default-src \'self\' data:; script-src \'self\'; style-src \'self\' \'unsafe-inline\'; connect-src \'none\'; form-action \'none\'; base-uri \'none\'">');
 for(const el of $('link[href],img[src]').toArray()){const attr=el.name==='img'?'src':'href';$(el).attr(attr,await asset($(el).attr(attr)));$(el).removeAttr('srcset sizes loading');}
 $('*').each((_,el)=>{for(const key of Object.keys(el.attribs||{})){if(key.startsWith('on'))$(el).removeAttr(key);}});
 $('form').removeAttr('action');
 $('.fp-woo-shell').attr('id','catalogo');$('.fp-home-about').attr('id','nosotros');$('.site-footer').attr('id','contacto');
 $('.fp-contact-bar').insertBefore('header.wp-block-template-part');
 $('a').each((_,el)=>{const a=$(el),href=a.attr('href')||'';a.attr('data-original-href',href).removeAttr('target');
 if(a.hasClass('add_to_cart_button')){a.replaceWith(`<button type="button" class="button wp-element-button proto-add">${a.text()}</button>`);return;}
 if(href.includes('cotizacion'))a.attr('href','#seleccion');
 else if(href.includes('nosotros'))a.attr('href','#nosotros');
 else if(href.includes('tienda')||href.includes('categoria')){a.attr('href','#catalogo');if(href.includes('categoria'))a.attr('data-category',a.text().trim());}
 else if(href.includes('/producto/'))a.attr('href','#catalogo').attr('data-detail','true');
 else if(href==='/'||href===base)a.attr('href','#inicio');
 else if(!href.startsWith('#'))a.attr('href','#contacto').attr('data-simulation','true');
 });
 $('body').attr('id','inicio').prepend('<div class="proto-notice">PROTOTIPO VISUAL · No envía solicitudes · Cantidades solo en memoria</div>');
 $('.quantity').each((_,el)=>{$(el).addClass('proto-quantity').prepend('<button type="button" data-step="-1" aria-label="Disminuir cantidad">−</button>').append('<button type="button" data-step="1" aria-label="Aumentar cantidad">+</button>');});
 $('.fpw-card-selection').remove();
 $('.fp-woo-shell > h2').after('<p id="filter-state" hidden></p>');
 $('main').append('<section id="seleccion" class="proto-selection"><h2>Productos a Cotizar</h2><p>Simulación visual. No se enviará ninguna solicitud.</p><ul id="selection-items"></ul><p id="selection-empty">Aún no has agregado productos.</p><button type="button" id="simulate-send">Simular solicitud de cotización</button><button type="button" id="clear-selection">Vaciar selección</button><p id="selection-feedback" role="status"></p></section>');
 $('body').append('<div id="proto-feedback" role="status"></div><aside class="proto-switcher" aria-label="Comparar variantes"><button type="button" id="variant-prev" aria-label="Variante anterior">←</button><div><strong id="variant-label"></strong><span id="variant-summary"></span><div class="variant-tabs"><button data-variant="A">A</button><button data-variant="B">B</button><button data-variant="C">C</button></div></div><button type="button" id="variant-next" aria-label="Variante siguiente">→</button></aside>');
 $('head').append('<link rel="stylesheet" href="prototype.css">');
 const banner=await asset('/wp-content/themes/freeplast/assets/js/banner.js');
 $('body').append(`<script src="${banner}" defer></script><script src="prototype.js" defer></script>`);
 fs.writeFileSync(path.join(root,'index.html'),$.html());
 console.log('Captured', $('.product-card').length,'products;',cache.size,'assets');
})();
