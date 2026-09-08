/* PROTOTYPE — in-memory rendering and simulated actions, NOT a Woo adapter.
   Question: which composition makes a multi-product quote request clearest?
   A: Directa (filters + grid). B: Por categorías (rail + grouped grids).
   C: Con resumen (catalog workspace + live selection panel).
   Native WordPress sessions, forms and submissions must be used in real implementation. */
(() => {
  const products = window.CATALOG;
  const $ = (s, root = document) => root.querySelector(s);
  const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
  const icons = {
    arrow: '<path d="M4 12h15M13 5l7 7-7 7"/>', back: '<path d="M20 12H5m6-7-7 7 7 7"/>',
    search:'<circle cx="10.5" cy="10.5" r="6.5"/><path d="m16 16 4 4"/>',
    menu:'<path d="M4 6h16M4 12h16M4 18h16"/>', chevron:'<path d="m9 5 7 7-7 7"/>', down:'<path d="m5 9 7 7 7-7"/>',
    check:'<path d="m5 12 4 4L19 6"/>', trash:'<path d="M3 6h18M9 6V3h6v3M6 6l1 15h10l1-15M10 10v7m4-7v7"/>',
    box:'<path d="m3 7 9-4 9 4v10l-9 4-9-4V7Zm0 0 9 5 9-5M12 12v9M7 5l10 5"/>',
    shield:'<path d="M12 2 3 6v6c0 5 9 10 9 10s9-5 9-10V6l-9-4Z"/><path d="m8 12 3 3 5-6"/>',
    image:'<rect x="3" y="3" width="18" height="18" rx="3"/><circle cx="8" cy="8" r="1"/><path d="m3 17 6-6 4 4 3-3 5 5"/>',
    help:'<circle cx="12" cy="12" r="9"/><path d="M9 9a3 3 0 0 1 6 0c0 2-3 2-3 4m0 3v1"/>',
    tune:'<path d="M4 6h16M4 12h16M4 18h16M8 3v6m8 0v6M10 15v6"/>',
    mail:'<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 6 9 7 9-7"/>',
    clock:'<circle cx="12" cy="12" r="9"/><path d="M12 6v6l4 2"/>', info:'<circle cx="12" cy="12" r="9"/><path d="M12 11v6m0-10v1"/>'
  };
  const icon = n => `<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${icons[n] || icons.box}</svg>`;
  const variants = {A:'Directa',B:'Por categorías',C:'Con resumen'};
  const state = {variant:'A', view:'catalog', product:products[0].slug, filter:'all',query:'',sort:'featured',basket:[],drafts:{},colors:{},form:{dispatch:''},errors:{},failure:false,sending:false,submitted:null};
  let sendTimer, toastTimer;
  const product = slug => products.find(p => p.slug === slug);
  const totals = (lines=state.basket) => ({lines:lines.length,units:lines.reduce((n,l)=>n+l.qty,0)});
  const plural = (n,one,many=one+'s') => `${n.toLocaleString('es-CL')} ${n===1?one:many}`;
  const qty = v => {const n=Math.floor(Number(v));return Number.isSafeInteger(n)&&n>0?n:1;};
  const lineKey = l => `${l.slug}|${l.color||''}`;
  const getLine = key => state.basket.find(l=>lineKey(l)===key);
  const amountFor = slug => state.basket.filter(l=>l.slug===slug).reduce((n,l)=>n+l.qty,0);
  const categoryLabel = c => c==='agricola'?'Agrícola':'Otros';
  const known = v => v && v !== 'Consultar';
  const readableSpec = v => known(v)?esc(v):'Por confirmar';
  const urlFor = (view, slug) => `?variant=${state.variant}&view=${view}${slug?'&product='+encodeURIComponent(slug):''}`;
  const routeLink = (view,text,cls='',slug) => `<a class="${cls}" href="${urlFor(view,slug)}" data-route="${view}"${slug?` data-product="${esc(slug)}"`:''}>${text}</a>`;
  function readURL() {
    const u = new URL(location.href);
    state.variant = variants[u.searchParams.get('variant')]?u.searchParams.get('variant'):'A';
    const view=u.searchParams.get('view');
    state.view=['catalog','product','basket','details','confirmation'].includes(view)?view:'catalog';
    state.product=product(u.searchParams.get('product'))?u.searchParams.get('product'):products[0].slug;
    if(state.view==='confirmation'&&!state.submitted) state.view='basket';
  }
  function setURL(replace=false) {
    const u=new URL(location.href);u.searchParams.set('variant',state.variant);u.searchParams.set('view',state.view);
    if(state.view==='product')u.searchParams.set('product',state.product);else u.searchParams.delete('product');
    history[replace?'replaceState':'pushState']({},'',u);
  }
  function go(view,slug) {
    if(state.sending)return;
    closeDialogs();clearTimeout(toastTimer);$('#toast').classList.remove('visible');state.view=view;if(slug)state.product=slug;
    setURL();render();window.scrollTo({top:0,behavior:'instant'});$('#main').focus({preventScroll:true});
  }
  function closeDialogs(){document.querySelectorAll('dialog[open]').forEach(d=>d.close());}
  function toast(message){clearTimeout(toastTimer);$('#toast').textContent=message;$('#toast').classList.add('visible');toastTimer=setTimeout(()=>$('#toast').classList.remove('visible'),3200);}
  function header(){
    const t=totals();
    $('#header').innerHTML=`<div class="shell header-inner">${routeLink('catalog','<img src="assets/mark.svg" alt="" width="32" height="32"><span>Freeplast</span>','brand')}<nav class="desktop-nav" aria-label="Navegación principal"><a href="${urlFor('catalog')}" data-route="catalog" ${['catalog','product'].includes(state.view)?'aria-current="page"':''}>Catálogo</a><button data-action="help">Cómo cotizar</button><button data-action="help">Contacto</button></nav>${routeLink('basket',`<span class="selection-label">Productos a Cotizar</span><span class="count" aria-label="${t.lines} ${t.lines===1?'producto distinto':'productos distintos'}">${t.lines}</span>`,'header-selection')}<button class="icon-button menu-trigger" data-action="menu" aria-label="Abrir menú">${icon('menu')}</button></div>`;
  }
  function steps(current=1){return `<ol class="stepper" aria-label="Pasos de la solicitud">${['Productos','Tus datos','Solicitud'].map((s,i)=>`<li class="${i+1===current?'active':i+1<current?'done':''}" ${i+1===current?'aria-current="step"':''}><span class="step-number">${i+1<current?icon('check'):i+1}</span><span>${s}</span></li>`).join('')}</ol>`;}
  function filters(){return `<div class="filter-tabs" aria-label="Categorías">${[['all','Todos'],['agricola','Agrícola'],['otros','Otros']].map(([k,l])=>`<button class="filter" data-filter="${k}" aria-pressed="${state.filter===k}">${l}<span>${k==='all'?products.length:products.filter(p=>p.category===k).length}</span></button>`).join('')}</div>`;}
  function search(){return `<form class="search-form" id="search-form" role="search">${icon('search')}<label class="sr-only" for="search">Buscar productos</label><input id="search" type="search" value="${esc(state.query)}" placeholder="Busca una caja, traversa…" autocomplete="off"><button aria-label="Buscar productos" type="submit">${icon('arrow')}</button></form>`;}
  function photo(p,cls='product-photo'){return `<span class="${cls}">${p.missingImage?`<span class="placeholder">${icon('image')}<span>Foto pendiente</span></span>`:`<img src="${esc(p.image)}" alt="${esc(p.imageAlt)}" width="300" height="300" loading="lazy">`}</span>`;}
  function quantityUI(p,context='draft',key=p.slug,value=state.drafts[p.slug]||1){return `<div class="qty-control"><button type="button" data-qty="-1" data-context="${context}" data-key="${esc(key)}" aria-label="Reducir cantidad de ${esc(p.title)}">−</button><input type="number" min="1" step="1" inputmode="numeric" value="${value}" data-quantity data-context="${context}" data-key="${esc(key)}" aria-label="Cantidad de ${esc(p.title)}"><button type="button" data-qty="1" data-context="${context}" data-key="${esc(key)}" aria-label="Aumentar cantidad de ${esc(p.title)}">+</button></div>`;}
  function card(p){
    const count=amountFor(p.slug);
    const dimensions=known(p.specs.dimensions)?p.specs.dimensions.replace(' (exteriores)',''):null;
    const spec=dimensions|| (known(p.specs.material_short)?p.specs.material_short:known(p.specs.weight)?p.specs.weight:'Ficha técnica por confirmar');
    const extra=known(p.specs.use)?p.specs.use:'Consulta las características en la ficha.';
    return `<article class="product-card" data-card="${p.slug}">${routeLink('product',photo(p),'photo-link',p.slug)}<div class="product-info"><span class="product-category">${categoryLabel(p.category)}</span><h2>${routeLink('product',esc(p.title),'',p.slug)}</h2><p class="product-spec">${esc(spec)}</p><p class="card-extra">${esc(extra)}</p></div>${p.options.length?`<div class="product-controls variant-controls"><span class="color-hint"><span class="color-dots" aria-hidden="true"><i class="color-dot"></i><i class="color-dot red"></i><i class="color-dot yellow"></i><i class="color-dot blue"></i><i class="color-dot green"></i></span>5 colores · sujetos a disponibilidad</span>${routeLink('product',`Elegir color ${icon('arrow')}`,'button secondary',p.slug)}</div>`:`<div class="product-controls"><div class="qty-group"><span class="qty-label">Cantidad · unidades</span>${quantityUI(p)}</div><button class="button" data-add="${p.slug}" aria-label="Agregar ${esc(p.title)} a Productos a Cotizar">Agregar ${icon('arrow')}</button></div>`}<div class="added-state ${count?'':'is-empty'}">${count?`${routeLink('basket',`${icon('check')} ${plural(count,'unidad','unidades')} agregada${count===1?'':'s'}`)}<button class="text-button" data-remove-product="${p.slug}" aria-label="Quitar ${esc(p.title)} de Productos a Cotizar">Quitar</button>`:''}</div></article>`;
  }
  function miniLines(lines=state.basket){return `<ul class="mini-lines">${lines.map(l=>`<li><span>${esc(product(l.slug).title)}${l.color?`<small>Color: ${esc(l.color)}</small>`:''}</span><b>× ${l.qty.toLocaleString('es-CL')}</b></li>`).join('')}</ul>`;}
  function livePanel(){const t=totals();return `<div class="selected-panel"><p class="eyebrow">Tu solicitud, en preparación</p><h2>Productos a Cotizar</h2>${t.lines?`<p class="fine">${plural(t.lines,'producto')} · ${plural(t.units,'unidad','unidades')}</p>${miniLines()}${routeLink('basket',`Revisar selección ${icon('arrow')}`,'button wide')}`:`<p class="empty-preview">Los productos que agregues aparecerán aquí. Puedes combinar distintos productos y cantidades en una sola solicitud.</p><div class="notice-icon fine">${icon('box')}<span>Empieza por el catálogo.</span></div>`}<hr><p class="fine">Sin registro, pago ni reserva de stock.</p></div>`;}
  function VariantA(list){return `<section class="catalog-intro"><div class="catalog-heading-row"><div><p class="eyebrow">Catálogo mayorista · Freeplast</p><h1>Elige tus productos.</h1><p class="intro-copy">Agrega las cantidades que necesitas. Nosotros preparamos tu cotización.</p></div><div class="intro-note">${icon('shield')}<div><strong>Una solicitud. Sin compromiso de compra.</strong>Sin registro ni pago en línea.</div></div></div></section><div class="catalog-tools">${search()}${filters()}</div>${resultList(list)}`;}
  function VariantB(list){return `<section class="catalog-intro"><div class="catalog-heading-row"><div><p class="eyebrow">Soluciones plásticas para tu actividad</p><h1>Todo para tu próxima solicitud.</h1><p class="intro-copy">Encuentra tus productos. Define cantidades. Cotiza en un solo recorrido.</p></div></div><div class="intro-steps"><span><b>1</b> Selecciona productos</span><span><b>2</b> Completa tus datos</span><span><b>3</b> Solicita tu cotización</span></div></section><div class="catalog-layout with-aside"><aside class="catalog-aside category-aside"><div><p class="eyebrow">Explora el catálogo</p>${filters()}<div class="aside-instructions"><h3>Tu solicitud, paso a paso</h3><ol><li>Agrega la cantidad de cada producto.</li><li>Elige color si corresponde.</li><li>Revisa y completa tus datos.</li></ol><button class="text-button" data-action="help">Te ayudamos a elegir</button></div></div></aside><div class="catalog-main"><div class="catalog-tools">${search()}${filters()}</div>${resultList(list,true)}</div></div>`;}
  function VariantC(list){return `<section class="catalog-intro"><div class="catalog-heading-row"><div><p class="eyebrow">Catálogo Freeplast</p><h1>Arma tu solicitud de cotización.</h1><p class="intro-copy">Tus productos y cantidades, siempre a la vista.</p></div><div class="intro-note">${icon('box')}<div><strong>${products.length} productos</strong>Un solo lugar para cotizar.</div></div></div></section><div class="mobile-preview"><details><summary class="preview-toggle">${plural(totals().lines,'producto')} en tu selección ${icon('down')}</summary><div>${totals().lines?miniLines():'<p class="fine">Aún no agregas productos.</p>'}${routeLink('basket','Revisar selección','text-button')}</div></details></div><div class="catalog-layout"><div class="catalog-main"><div class="catalog-tools">${search()}${filters()}</div>${resultList(list)}</div><aside class="catalog-aside">${livePanel()}</aside></div>`;}
  function resultList(list,grouped=false){
    const top=`<div class="results-toolbar"><span><strong>${list.length}</strong> ${state.query?`resultados para “${esc(state.query)}”`:'productos'}</span><label class="sort">Ordenar <select id="sort" aria-label="Ordenar productos"><option value="featured" ${state.sort==='featured'?'selected':''}>Destacados</option><option value="name" ${state.sort==='name'?'selected':''}>Nombre A–Z</option></select></label></div>`;
    if(!list.length)return top+`<div class="empty-state"><span class="empty-icon">${icon('search')}</span><h2>No encontramos “${esc(state.query)}”.</h2><p>Prueba con otro nombre o vuelve a explorar el catálogo.</p><div class="action-row"><button class="button" data-action="clear-search">Ver todos los productos</button><button class="text-button" data-action="help">Consultar con ventas</button></div></div>`;
    if(grouped&&state.filter==='all'&&!state.query)return top+['agricola','otros'].map(c=>`<section><div class="section-title"><h2>${categoryLabel(c)}</h2><span>${plural(list.filter(p=>p.category===c).length,'producto')}</span></div><div class="catalog-grid">${list.filter(p=>p.category===c).map(card).join('')}</div></section>`).join('');
    return top+`<div class="catalog-grid">${list.map(card).join('')}</div>`;
  }
  function catalog(){
    const norm=s=>s.normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase();
    const query=norm(state.query);
    const list=products.filter(p=>(state.filter==='all'||p.category===state.filter)&&norm(p.title+' '+p.excerpt).includes(query));
    list.sort((a,b)=>state.sort==='name'?a.title.localeCompare(b.title,'es'):Number(b.featured)-Number(a.featured));
    return `<div class="shell">${({A:VariantA,B:VariantB,C:VariantC}[state.variant])(list)}</div>`;
  }
  function detail(){
    const p=product(state.product),selected=state.colors[p.slug]||'',n=amountFor(p.slug);
    const colorClass={Blanco:'',Rojo:'red',Amarillo:'yellow',Azul:'blue',Verde:'green'};
    const specs=[['Material',p.specs.material_short],['Medidas',p.specs.dimensions],['Peso propio',p.specs.weight],['Uso',p.specs.use]];
    const excerpt=/cliente|sin descripción|provisional/.test(p.excerpt)?'Las especificaciones de este producto están pendientes de confirmación. Puedes incluirlo en tu solicitud para consultar con ventas.':p.excerpt;
    return `<div class="shell product-page"><nav class="breadcrumb" aria-label="Ruta de navegación">${routeLink('catalog','Catálogo')}${icon('chevron')}<span>${categoryLabel(p.category)}</span></nav><div class="product-heading"><p class="eyebrow">${categoryLabel(p.category)} · Freeplast</p><h1>${esc(p.title)}</h1></div><div class="product-layout"><div class="product-gallery"><div class="detail-image">${p.missingImage?`<div class="missing-detail">${icon('image')}<span>Fotografía pendiente</span></div>`:`<img src="${esc(p.image)}" alt="${esc(p.imageAlt)}" width="600" height="600">`}</div><p class="image-caption">${p.missingImage?'La fotografía de este producto está por confirmar.':'Fotografía referencial. Puede no representar el color o la configuración seleccionados.'}</p></div><div class="product-summary"><h2 class="summary-title">Detalles que importan.</h2><p class="detail-description">${esc(excerpt)}</p>${specs.some(([,v])=>known(v))?`<dl class="spec-grid">${specs.map(([label,value])=>`<div><dt>${label}</dt><dd>${readableSpec(value)}</dd></div>`).join('')}</dl>`:'<p class="notice">Las especificaciones técnicas están por confirmar con ventas.</p>'}<div class="product-options">${p.options.length?`<fieldset><legend>Elige un color <span class="muted">· obligatorio</span></legend><div class="colors">${p.options.map(o=>`<button type="button" class="color-option ${selected===o.label?'selected':''}" data-color="${o.label}" aria-pressed="${selected===o.label}"><span class="color-dot ${colorClass[o.label]}" aria-hidden="true"></span>${o.label}${selected===o.label?icon('check'):''}</button>`).join('')}</div></fieldset><p class="fine">Colores sujetos a disponibilidad. ${selected?`Seleccionado: <strong>${esc(selected)}</strong>.`:'Selecciona un color para poder agregar.'}</p>`:''}<div class="product-add"><div class="qty-group"><span class="qty-label">Cantidad · unidades</span>${quantityUI(p)}</div><button class="button" data-add="${p.slug}" ${p.options.length&&!selected?'disabled':''}>Agregar a cotización ${icon('arrow')}</button></div><p class="product-help">${icon('shield')} Sin compra ni reserva de stock.</p></div>${n?`<div class="detail-added"><p class="notice-icon">${icon('check')}<strong>${plural(n,'unidad','unidades')} de este producto en tu selección.</strong></p>${routeLink('basket',`Revisar Productos a Cotizar ${icon('arrow')}`)}</div>`:''}<div class="product-facts"><details><summary>Ficha técnica completa</summary><table class="spec-table"><tbody>${[['Material',p.specs.material],['Medidas',p.specs.dimensions],['Peso propio',p.specs.weight],['Uso',p.specs.use],...(p.specs.units_per_pallet?[['Unidades por pallet',String(p.specs.units_per_pallet)]]:[])].map(([k,v])=>`<tr><th scope="row">${k}</th><td>${readableSpec(v)}</td></tr>`).join('')}</tbody></table>${p.specs.units_per_pallet?'<p class="fine">Unidades por pallet es un dato de embalaje, no una cantidad mínima de solicitud.</p>':''}</details></div></div></div><section class="related-block"><div class="section-title"><h2>Sigue completando tu selección</h2>${routeLink('catalog','Ver catálogo','text-link')}</div><div class="catalog-grid">${products.filter(x=>x.slug!==p.slug&&x.category===p.category).slice(0,3).map(card).join('')}</div></section></div>`;
  }
  function summaryCard({lines=state.basket,edit=false,continueButton=false}={}){
    const t=totals(lines);return `<div class="summary-card"><h2>Resumen de tu selección</h2><div class="summary-numbers"><div><strong>${t.lines}</strong><span>${t.lines===1?'producto distinto':'productos distintos'}</span></div><div><strong>${t.units.toLocaleString('es-CL')}</strong><span>unidades en total</span></div></div>${edit?miniLines(lines):''}${continueButton?`${routeLink('details',`Continuar con mis datos ${icon('arrow')}`,'button wide')}<p class="fine">El siguiente paso es completar tus datos de contacto, empresa y despacho.</p><div class="summary-links">${routeLink('catalog','Seguir agregando productos','text-button')}</div>`:''}<p class="fine">Ventas confirmará precios, disponibilidad y condiciones. No estás realizando una compra.</p></div>`;
  }
  function basket(){
    return `<div class="shell"><div class="page-heading">${steps(1)}<p class="eyebrow">Revisa tu selección</p><h1>Productos a Cotizar</h1><p>Comprueba productos, colores y cantidades antes de continuar.</p></div>${state.basket.length?`<div class="basket-layout"><div><div class="line-split"><h2 class="sr-only">Productos seleccionados</h2><span class="fine">${plural(totals().lines,'producto')} seleccionado${totals().lines===1?'':'s'}</span>${routeLink('catalog',`${icon('back')} Agregar productos`,'text-button')}</div><div class="basket-list">${state.basket.map(l=>{const p=product(l.slug);return `<article class="basket-line">${routeLink('product',photo(p),'',p.slug)}<div><h2>${routeLink('product',esc(p.title),'',p.slug)}</h2><p class="line-variant">${l.color?'Color: '+esc(l.color):categoryLabel(p.category)}</p></div><div class="line-actions"><div class="qty-group"><span class="qty-label">Cantidad · unidades</span>${quantityUI(p,'basket',lineKey(l),l.qty)}</div><button class="remove-button" data-remove="${esc(lineKey(l))}" aria-label="Quitar ${esc(p.title)}${l.color?' '+esc(l.color):''}">${icon('trash')}</button></div></article>`;}).join('')}</div><p class="selection-hint">${icon('check')} Las cantidades se actualizan al modificarlas.</p></div><aside>${summaryCard({continueButton:true})}</aside></div>`:`<div class="empty-state large"><div class="empty-icon">${icon('box')}</div><h2>Aún no agregas productos.</h2><p>Explora el catálogo y reúne lo que necesitas en una sola solicitud de cotización.</p>${routeLink('catalog',`Elegir productos ${icon('arrow')}`,'button')}<button class="text-button" data-action="help">¿No sabes qué producto elegir?</button></div>`}</div>`;
  }
  const fieldDefs={name:['Nombre','text','name','Tu nombre'],phone:['Teléfono','tel','tel','Ej.: +56 9 1234 5678'],email:['Email','email','email','nombre@empresa.cl'],company:['Nombre de empresa','text','organization','Nombre o razón social'],rut:['RUT empresa','text','off','76.123.456-7'],giro:['Giro','text','off','Ej.: producción agrícola'],address:['Dirección de despacho','textarea','street-address','Calle, número, comuna y región'],message:['Mensaje','textarea','off','¿Qué más debería saber el equipo de ventas?']};
  function field(name,full=false){const [label,type,auto,placeholder]=fieldDefs[name],error=state.errors[name];return `<label class="field ${full?'full':''}" for="f-${name}"><span class="field-label">${label}${name==='message'?'<small>Opcional</small>':''}</span>${type==='textarea'?`<textarea id="f-${name}" name="${name}" autocomplete="${auto}" maxlength="${name==='address'?800:2000}" placeholder="${placeholder}" ${name==='address'?'required':''} ${error?`aria-invalid="true" aria-describedby="error-${name}"`:''}>${esc(state.form[name]||'')}</textarea>`:`<input id="f-${name}" name="${name}" type="${type}" autocomplete="${auto}" maxlength="240" required value="${esc(state.form[name]||'')}" placeholder="${placeholder}" ${error?`aria-invalid="true" aria-describedby="error-${name}"`:''}>`}${error?`<span class="field-error" id="error-${name}">${esc(error)}</span>`:''}</label>`;}
  function errorSummary(){const entries=Object.entries(state.errors);return entries.length?`<div class="error-summary" role="alert" tabindex="-1" id="error-summary"><h2>Revisa ${plural(entries.length,'campo')} para continuar.</h2><p class="fine">Tus productos y los datos que completaste siguen aquí.</p><ul>${entries.map(([name,error])=>`<li><a href="#${name==='dispatch'?'dispatch-options':'f-'+name}">${esc(error)}</a></li>`).join('')}</ul></div>`:'';}
  function details(){
    if(!state.basket.length)return basket();
    const t=totals();return `<div class="shell"><div class="page-heading">${steps(2)}<p class="eyebrow">El último paso antes de solicitar</p><h1>Tus datos y despacho.</h1><p>Cuéntanos a quién responder y si necesitas despacho. Sin registro ni pago en línea.</p></div><div class="checkout-layout"><form id="details-form" class="checkout-form" novalidate><p class="notice notice-icon"><span>${icon('info')}</span><span><strong>Solo una demostración.</strong> Usa datos ficticios. Nada se envía ni se guarda al recargar.</span></p><div class="fine" id="required-hint">Todos los campos son obligatorios, salvo Mensaje.</div>${errorSummary()}<fieldset class="form-section"><legend><span class="section-number">01</span> Contacto</legend><div class="fields">${field('name',true)}${field('phone')}${field('email')}</div></fieldset><fieldset class="form-section"><legend><span class="section-number">02</span> Empresa</legend><p class="section-hint">Estos datos se utilizarán para una eventual facturación.</p><div class="fields">${field('company',true)}${field('rut')}${field('giro')}</div></fieldset><fieldset class="form-section"><legend><span class="section-number">03</span> Despacho</legend><div class="fields"><div class="full"><p class="dispatch-label" id="dispatch-label">¿Necesitas despacho?</p><div id="dispatch-options" class="dispatch-options" role="radiogroup" aria-labelledby="dispatch-label" ${state.errors.dispatch?'aria-describedby="error-dispatch" aria-invalid="true"':''}>${[['si','Con despacho'],['no','Sin despacho']].map(([v,l])=>`<label class="dispatch-option"><input type="radio" name="dispatch" value="${v}" required ${state.form.dispatch===v?'checked':''}>${l}</label>`).join('')}</div>${state.errors.dispatch?`<p class="field-error" id="error-dispatch">${state.errors.dispatch}</p>`:''}</div>${state.form.dispatch==='si'?field('address',true):''}</div></fieldset><fieldset class="form-section"><legend><span class="section-number">04</span> Algo más que debamos saber</legend><div class="fields">${field('message',true)}</div></fieldset><div class="form-submit">${state.failure?`<div class="error-summary submit-error" role="alert" tabindex="-1" id="submit-error"><h2>No pudimos enviar tu solicitud.</h2><p>Este fallo es simulado. Tus ${plural(t.lines,'producto')} y tus datos siguen aquí. Puedes volver a intentarlo sin completarlos de nuevo.</p></div>`:''}<p class="privacy-copy">Usaremos tus datos para preparar y responder tu solicitud de cotización. Consulta nuestra <button type="button" data-action="privacy">política de privacidad</button>.</p><button class="button" type="submit" ${state.sending?'disabled aria-busy="true"':''}>${state.sending?'<span class="spinner" aria-hidden="true"></span> Enviando…':state.failure?'Volver a intentar':`Solicitar cotización ${icon('arrow')}`}</button><p class="fine">${icon('shield')} Sin pagos ni reserva de stock · envío simulado</p></div></form><aside class="checkout-summary"><div class="summary-card"><details ${matchMedia('(min-width:1000px)').matches?'open':''}><summary><span>${plural(t.lines,'producto')} · ${plural(t.units,'unidad','unidades')}</span>${icon('down')}</summary>${miniLines()}<p class="fine">El precio se confirma con ventas.</p></details>${routeLink('basket','Editar productos','text-button')}</div></aside></div></div>`;
  }
  function confirmation(){
    const s=state.submitted;if(!s)return basket();
    return `<div class="shell"><div class="confirmation">${steps(3)}<div class="confirmation-hero"><span class="success-mark">${icon('check')}</span><p class="eyebrow">Así se verá la confirmación</p><h1>Solicitud recibida.</h1><p>Gracias, ${esc(s.form.name||'por tu interés')}. Tu selección está lista para que ventas prepare una cotización.</p><div class="reference">DEMO · FP-2026-000001</div><p class="demo-reference">Referencia ficticia. No se creó ninguna solicitud real ni se envió un correo.</p></div><div class="confirmation-content"><h2>¿Qué sigue ahora?</h2><ol class="next-steps"><li><span class="step-number">1</span><div><strong>Ventas revisa tu solicitud.</strong><p>Confirmará disponibilidad, precios y condiciones de los productos seleccionados.</p></div></li><li><span class="step-number">2</span><div><strong>Te contactaremos con los detalles.</strong><p>En el sitio real se usarán el email o teléfono que ingresaste. No estás realizando una compra.</p></div></li></ol>${summaryCard({lines:s.lines,edit:true})}<p class="notice">${s.form.dispatch==='si'?`Despacho solicitado: ${esc(s.form.address)}.`:'Solicitud sin despacho.'} ${s.form.email?`Email de ejemplo: ${esc(s.form.email)}.`:''}</p></div><div class="action-row">${routeLink('catalog','Volver al catálogo','button secondary')}<button class="text-button" data-action="lab">Probar otro escenario</button></div></div></div>`;
  }
  function dock(){const t=totals();$('#selection-dock').innerHTML=t.lines&&['catalog','product'].includes(state.view)?`<aside class="selection-dock" aria-label="Resumen de Productos a Cotizar"><div><strong>${plural(t.lines,'producto')} seleccionado${t.lines===1?'':'s'}</strong><span>${plural(t.units,'unidad','unidades')} · sin pago en línea</span></div>${routeLink('basket',`Revisar ${icon('arrow')}`,'button')}</aside>`:'';}
  function labBar(){
    $('#prototype-bar').innerHTML=`<button data-switch="-1" aria-label="Propuesta anterior">${icon('back')}</button><span class="prototype-label"><small>Comparar diseño · ${Object.keys(variants).indexOf(state.variant)+1}/3</small><strong>${state.variant} · ${variants[state.variant]}</strong></span><button data-switch="1" aria-label="Propuesta siguiente">${icon('arrow')}</button><button class="scenario-button" data-action="lab">${icon('tune')} Escenarios</button>`;
    $('#state-output').textContent=JSON.stringify({prototipo:true,propuesta:state.variant,pantalla:state.view,productos:state.basket.map(l=>({producto:product(l.slug).title,color:l.color||null,cantidad:l.qty})),totales:totals(),despacho:state.form.dispatch||'sin elegir',camposCompletados:Object.keys(state.form).filter(k=>state.form[k]),errores:Object.keys(state.errors),enviando:state.sending,errorSimulado:state.failure,confirmacionSimulada:!!state.submitted},null,2);
  }
  function render(){
    const focused=document.activeElement;
    const focusId=focused?.id, focusLabel=focused?.getAttribute('aria-label'), focusColor=focused?.dataset?.color;
    document.body.dataset.variant=state.variant;document.body.dataset.view=state.view;document.body.classList.toggle('has-selection',state.basket.length>0&&['catalog','product'].includes(state.view));
    header();$('#main').innerHTML=({catalog,product:detail,basket,details,confirmation}[state.view])();dock();labBar();
    const title={catalog:'Catálogo',product:product(state.product).title,basket:'Productos a Cotizar',details:'Tus datos y despacho',confirmation:'Confirmación simulada'}[state.view];
    document.title=`${title} · ${state.variant} ${variants[state.variant]} · Prototipo Freeplast`;
    const restore=focusId?document.getElementById(focusId):focusColor?[...document.querySelectorAll('[data-color]')].find(e=>e.dataset.color===focusColor):focusLabel?[...document.querySelectorAll('button[aria-label],input[aria-label]')].find(e=>e.getAttribute('aria-label')===focusLabel):null;
    restore?.focus({preventScroll:true});
  }
  function switchVariant(direction){
    const keys=Object.keys(variants);state.variant=keys[(keys.indexOf(state.variant)+direction+keys.length)%keys.length];setURL(true);render();
    clearTimeout(toastTimer);$('#toast').classList.remove('visible');$('#toast').textContent=`Propuesta ${state.variant}: ${variants[state.variant]}. Selección conservada.`;
  }
  function updateQuantity(key,context,value){
    if(context==='basket'){const l=getLine(key);if(l)l.qty=qty(value);render();}
    else {state.drafts[key]=qty(value);document.querySelectorAll('input[data-quantity][data-context="draft"]').forEach(i=>{if(i.dataset.key===key)i.value=state.drafts[key];});}
  }
  function add(slug){
    const p=product(slug),color=p.options.length?(state.colors[slug]||''):'';
    if(p.options.length&&!color){go('product',slug);return;}
    const key=lineKey({slug,color}),n=state.drafts[slug]||1,existing=getLine(key);
    if(existing)existing.qty+=n;else state.basket.push({slug,color,qty:n});
    const y=scrollY;render();window.scrollTo({top:y,behavior:'instant'});toast(`${plural(n,'unidad','unidades')} de ${p.title}${color?' · '+color:''} agregadas.`);
  }
  function collectForm(){const f=$('#details-form');if(!f)return;new FormData(f).forEach((v,k)=>{state.form[k]=String(v);});}
  function validate(){
    state.errors={};for(const k of ['name','phone','email','company','rut','giro']){if(!String(state.form[k]||'').trim())state.errors[k]=`Completa ${fieldDefs[k][0].toLowerCase()}.`;}
    if(state.form.email&&!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(state.form.email))state.errors.email='Escribe un email válido, por ejemplo nombre@empresa.cl.';
    if(!['si','no'].includes(state.form.dispatch))state.errors.dispatch='Selecciona si necesitas despacho.';
    if(state.form.dispatch==='si'&&!String(state.form.address||'').trim())state.errors.address='Indica calle, número, comuna y región para el despacho.';
    return !Object.keys(state.errors).length;
  }
  function submit(){
    collectForm();if(!validate()){render();$('#error-summary').focus();return;}
    if(state.sending)return;state.failure=false;state.sending=true;render();
    sendTimer=setTimeout(()=>{
      state.sending=false;
      if(state.failNext){state.failNext=false;state.failure=true;render();$('#submit-error').focus();return;}
      state.submitted={lines:state.basket.map(l=>({...l})),form:{...state.form,address:state.form.dispatch==='si'?state.form.address:''}};
      state.basket=[];state.form={dispatch:''};go('confirmation');
    },1400);
  }
  function sample(){state.basket=[{slug:'caja-cosechera-3-4',color:'',qty:70},{slug:'caja-universal-cerrada-color',color:'Azul',qty:25},{slug:'traversa-para-bins-tipo-g1',color:'',qty:12}];}
  function fakeForm(){state.form={name:'Persona de ejemplo',phone:'+56 9 0000 0000',email:'compras@ejemplo.invalid',company:'Empresa ficticia de demostración',rut:'00.000.000-0',giro:'Actividad de ejemplo',dispatch:'si',address:'Calle de ejemplo 123, comuna y región ficticias',message:''};}
  function scenario(name){
    clearTimeout(sendTimer);state.sending=false;state.failure=false;state.failNext=false;state.errors={};closeDialogs();
    if(name==='reset'){state.basket=[];state.form={dispatch:''};state.submitted=null;state.drafts={};state.colors={};state.query='';state.filter='all';go('catalog');}
    if(name==='sample'){sample();go('basket');}
    if(name==='empty'){state.basket=[];go('basket');}
    if(name==='variant'){go('product','caja-universal-cerrada-color');}
    if(['form','errors','failure','success'].includes(name)){
      sample();fakeForm();
      if(name==='errors'){state.form={dispatch:''};validate();go('details');$('#error-summary').focus();}
      if(name==='form')go('details');
      if(name==='failure'){state.failNext=true;go('details');submit();}
      if(name==='success'){state.submitted={lines:state.basket.map(l=>({...l})),form:{...state.form}};state.basket=[];go('confirmation');}
    }
  }
  document.addEventListener('click',e=>{
    const route=e.target.closest('[data-route]');if(route){e.preventDefault();collectForm();go(route.dataset.route,route.dataset.product);return;}
    const b=e.target.closest('button');if(!b)return;
    if(b.dataset.switch){if(!state.sending){collectForm();switchVariant(Number(b.dataset.switch));}return;}
    if(b.dataset.filter){state.filter=b.dataset.filter;render();return;}
    if(b.dataset.add){add(b.dataset.add);return;}
    if(b.dataset.qty){const key=b.dataset.key,context=b.dataset.context,current=context==='basket'?getLine(key)?.qty:state.drafts[key]||1;const y=scrollY;updateQuantity(key,context,(current||1)+Number(b.dataset.qty));window.scrollTo({top:y,behavior:'instant'});return;}
    if(b.dataset.remove){const l=getLine(b.dataset.remove),p=product(l.slug);state.basket=state.basket.filter(x=>lineKey(x)!==b.dataset.remove);render();toast(`${p.title} quitada de la selección.`);return;}
    if(b.dataset.removeProduct){state.basket=state.basket.filter(x=>x.slug!==b.dataset.removeProduct);render();toast('Producto quitado de tu selección.');return;}
    if(b.dataset.color){state.colors[state.product]=b.dataset.color;const y=scrollY;render();window.scrollTo({top:y,behavior:'instant'});return;}
    if(b.dataset.scenario){scenario(b.dataset.scenario);return;}
    const a=b.dataset.action;if(a==='close-dialog'){b.closest('dialog').close();return;}
    if(['menu','help','privacy','lab'].includes(a)){closeDialogs();labBar();$('#'+a).showModal();return;}
    if(a==='clear-search'){state.query='';state.filter='all';render();return;}
  });
  document.addEventListener('submit',e=>{e.preventDefault();if(e.target.id==='search-form'){state.query=$('#search').value.trim();render();}if(e.target.id==='details-form')submit();});
  document.addEventListener('input',e=>{if(e.target.closest('#details-form')&&e.target.name&&e.target.type!=='radio')state.form[e.target.name]=e.target.value;if(e.target.id==='search'&&e.target.value===''){state.query='';}});
  document.addEventListener('change',e=>{
    if(e.target.matches('[data-quantity]')){updateQuantity(e.target.dataset.key,e.target.dataset.context,e.target.value);return;}
    if(e.target.id==='sort'){state.sort=e.target.value;render();return;}
    if(e.target.name==='dispatch'){collectForm();state.form.dispatch=e.target.value;const y=scrollY;render();window.scrollTo({top:y,behavior:'instant'});return;}
  });
  document.addEventListener('keydown',e=>{
    if(e.key==='Enter'&&e.target.matches('#details-form input:not([type="radio"])')){e.preventDefault();if(!state.sending)submit();return;}
    if(e.defaultPrevented||e.altKey||e.ctrlKey||e.metaKey||e.shiftKey||state.sending||document.querySelector('dialog[open]'))return;
    if(e.target.closest('input,textarea,select,[contenteditable="true"],[contenteditable=""]'))return;
    if(['ArrowLeft','ArrowRight'].includes(e.key)){e.preventDefault();collectForm();switchVariant(e.key==='ArrowRight'?1:-1);}
  });
  window.addEventListener('popstate',()=>{collectForm();readURL();render();window.scrollTo({top:0,behavior:'instant'});});
  const desktopQuery=matchMedia('(min-width:1000px)');desktopQuery.addEventListener('change',e=>{const d=$('.checkout-summary details');if(d)d.open=e.matches;});
  readURL();setURL(true);render();
})();
