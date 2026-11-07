/**
 * Organic UI v2 — Native SVG Charts
 * line · column · bar · pie · donut · sparkline · gauge · candlestick
 */
const NS = 'http://www.w3.org/2000/svg';
const make = (tag, attrs = {}) => {
  const node = document.createElementNS(NS, tag);
  Object.entries(attrs).forEach(([key, value]) => node.setAttribute(key, value));
  return node;
};
const text = (parent, value, attrs = {}) => {
  const node = make('text', attrs);
  node.textContent = value;
  parent.append(node);
  return node;
};
const title = (parent, value) => {
  if (!value) return;
  const node = make('title');
  node.textContent = value;
  parent.append(node);
};
const number = value => Number.isFinite(Number(value)) ? Number(value) : 0;
const clamp = (value, min, max) => Math.min(max, Math.max(min, value));
const colors = index => `var(--org-chart-color-${(index % 8) + 1})`;

function parse(el) {
  try { return JSON.parse(el.dataset.orgChartData || '[]'); }
  catch { return []; }
}
function normalize(raw) {
  if (Array.isArray(raw)) {
    return {
      categories: raw.map((item, i) => typeof item === 'object' ? (item.label ?? `${i + 1}`) : `${i + 1}`),
      series: [{ name: 'Série', data: raw.map(item => typeof item === 'object' ? number(item.value) : number(item)) }],
      items: raw
    };
  }
  const series = Array.isArray(raw?.series) ? raw.series.map((serie, i) => ({
    name: serie.name || `Série ${i + 1}`,
    data: Array.isArray(serie.data) ? serie.data.map(number) : []
  })) : [];
  const length = Math.max(0, ...series.map(s => s.data.length));
  return {
    categories: Array.isArray(raw?.categories) ? raw.categories : Array.from({ length }, (_, i) => `${i + 1}`),
    series,
    items: Array.isArray(raw?.items) ? raw.items : []
  };
}
function root(el, viewBox = '0 0 720 320', label = 'Gráfico') {
  const svg = make('svg', { viewBox, role: 'img', 'aria-label': el.dataset.orgChartLabel || label });
  svg.classList.add('org-chart-svg');
  return svg;
}
function grid(svg, { W, H, left, right, top, bottom, min, max, steps = 5, horizontal = false }) {
  const plotW = W - left - right, plotH = H - top - bottom;
  for (let i = 0; i < steps; i++) {
    const ratio = i / (steps - 1);
    if (!horizontal) {
      const y = top + plotH * ratio;
      svg.append(make('line', { x1: left, y1: y, x2: W - right, y2: y, class: 'org-chart-grid' }));
      text(svg, Math.round(max - (max - min) * ratio), { x: left - 10, y: y + 4, class: 'org-chart-axis', 'text-anchor': 'end' });
    } else {
      const x = left + plotW * ratio;
      svg.append(make('line', { x1: x, y1: top, x2: x, y2: H - bottom, class: 'org-chart-grid' }));
      text(svg, Math.round(min + (max - min) * ratio), { x, y: H - bottom + 22, class: 'org-chart-axis', 'text-anchor': 'middle' });
    }
  }
}
function bounds(series) {
  const values = series.flatMap(s => s.data).map(number);
  const max = Math.max(...values, 0, 1);
  const min = Math.min(...values, 0);
  return { min, max, range: max - min || 1 };
}
function legend(svg, series, W) {
  if (series.length <= 1) return;
  const width = Math.min(145, W / series.length);
  const total = width * series.length;
  series.forEach((serie, i) => {
    const x = (W - total) / 2 + i * width;
    svg.append(make('circle', { cx: x + 5, cy: 304, r: 4, fill: colors(i) }));
    text(svg, serie.name, { x: x + 15, y: 308, class: 'org-chart-axis' });
  });
}
function line(el, raw) {
  const { categories, series } = normalize(raw);
  if (!series.length) return empty(el);
  const W = 720, H = 330, left = 48, right = 24, top = 22, bottom = series.length > 1 ? 52 : 38;
  const svg = root(el, `0 0 ${W} ${H}`, 'Gráfico de linha');
  const { min, max, range } = bounds(series), plotW = W-left-right, plotH = H-top-bottom;
  grid(svg, { W, H, left, right, top, bottom, min, max });
  const count = Math.max(...series.map(s => s.data.length));
  categories.slice(0, count).forEach((label, i) => {
    const x = left + plotW * (count <= 1 ? .5 : i/(count-1));
    text(svg, label, { x, y: H-bottom+22, class:'org-chart-axis', 'text-anchor':'middle' });
  });
  series.forEach((serie, si) => {
    const points = serie.data.map((v, i) => [left + plotW*(serie.data.length<=1?.5:i/(serie.data.length-1)), top + plotH-(v-min)/range*plotH]);
    const path = points.map((p,i)=>(i?'L':'M')+p.join(' ')).join(' ');
    svg.append(make('path',{d:path,class:'org-chart-series','data-series':si,stroke:colors(si)}));
    points.forEach((p,i)=>{ const c=make('circle',{cx:p[0],cy:p[1],r:4,class:'org-chart-point',stroke:colors(si)}); title(c,`${serie.name}: ${serie.data[i]}`); svg.append(c); });
  });
  legend(svg, series, W); el.replaceChildren(svg);
}
function column(el, raw) {
  const { categories, series } = normalize(raw);
  if (!series.length) return empty(el);
  const W=720,H=330,left=48,right=24,top=22,bottom=series.length>1?52:42;
  const svg=root(el,`0 0 ${W} ${H}`,'Gráfico de colunas');
  const {min,max,range}=bounds(series), plotW=W-left-right, plotH=H-top-bottom;
  grid(svg,{W,H,left,right,top,bottom,min,max});
  const count=Math.max(...series.map(s=>s.data.length)), slot=plotW/Math.max(count,1), group=slot*.76, barW=group/Math.max(series.length,1);
  categories.slice(0,count).forEach((label,i)=>text(svg,label,{x:left+slot*i+slot/2,y:H-bottom+22,class:'org-chart-axis','text-anchor':'middle'}));
  series.forEach((serie,si)=>serie.data.forEach((v,i)=>{
    const zeroY=top+plotH-(0-min)/range*plotH, y=top+plotH-(v-min)/range*plotH, h=Math.abs(zeroY-y);
    const r=make('rect',{x:left+i*slot+(slot-group)/2+si*barW+2,y:Math.min(y,zeroY),width:Math.max(1,barW-4),height:Math.max(1,h),rx:4,class:'org-chart-column',fill:colors(si)});
    title(r,`${serie.name}: ${v}`); svg.append(r);
  }));
  legend(svg,series,W); el.replaceChildren(svg);
}
function bar(el, raw) {
  const { categories, series }=normalize(raw); if(!series.length)return empty(el);
  const W=720,H=Math.max(300, categories.length*46+90),left=120,right=30,top=22,bottom=series.length>1?52:38;
  const svg=root(el,`0 0 ${W} ${H}`,'Gráfico de barras');
  const {min,max,range}=bounds(series),plotW=W-left-right,plotH=H-top-bottom;
  grid(svg,{W,H,left,right,top,bottom,min,max,horizontal:true});
  const count=Math.max(...series.map(s=>s.data.length)),slot=plotH/Math.max(count,1),group=slot*.72,barH=group/Math.max(series.length,1),zeroX=left+(0-min)/range*plotW;
  categories.slice(0,count).forEach((label,i)=>text(svg,label,{x:left-12,y:top+i*slot+slot/2+4,class:'org-chart-axis','text-anchor':'end'}));
  series.forEach((serie,si)=>serie.data.forEach((v,i)=>{
    const x=left+(v-min)/range*plotW, r=make('rect',{x:Math.min(x,zeroX),y:top+i*slot+(slot-group)/2+si*barH+2,width:Math.max(1,Math.abs(x-zeroX)),height:Math.max(1,barH-4),rx:4,class:'org-chart-bar',fill:colors(si)});
    title(r,`${serie.name}: ${v}`); svg.append(r);
  })); legend(svg,series,W); el.replaceChildren(svg);
}
function pieLike(el, raw, donutMode=false) {
  let items=Array.isArray(raw)?raw:(raw?.items||raw?.series?.[0]?.data||[]);
  items=items.map((item,i)=>typeof item==='object'?{label:item.label??item.name??`Item ${i+1}`,value:number(item.value??item.y)}:{label:`Item ${i+1}`,value:number(item)}).filter(i=>i.value>=0);
  const total=items.reduce((a,b)=>a+b.value,0); if(!total)return empty(el);
  const W=520,H=310,cx=155,cy=145,r=105,inner=donutMode?58:0; const svg=root(el,`0 0 ${W} ${H}`,donutMode?'Gráfico donut':'Gráfico de pizza');
  let start=-Math.PI/2;
  const point=(radius,angle)=>[cx+Math.cos(angle)*radius,cy+Math.sin(angle)*radius];
  items.forEach((item,i)=>{
    const angle=item.value/total*Math.PI*2,end=start+angle,large=angle>Math.PI?1:0,[x1,y1]=point(r,start),[x2,y2]=point(r,end); let d;
    if(inner){const [ix2,iy2]=point(inner,end),[ix1,iy1]=point(inner,start);d=`M ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} L ${ix2} ${iy2} A ${inner} ${inner} 0 ${large} 0 ${ix1} ${iy1} Z`;}
    else d=`M ${cx} ${cy} L ${x1} ${y1} A ${r} ${r} 0 ${large} 1 ${x2} ${y2} Z`;
    const path=make('path',{d,class:'org-chart-slice',fill:colors(i)});title(path,`${item.label}: ${item.value} (${Math.round(item.value/total*100)}%)`);svg.append(path);start=end;
  });
  items.forEach((item,i)=>{const y=52+i*26;svg.append(make('circle',{cx:310,cy:y-4,r:5,fill:colors(i)}));text(svg,item.label,{x:323,y,class:'org-chart-axis'});text(svg,`${Math.round(item.value/total*100)}%`,{x:485,y,class:'org-chart-axis','text-anchor':'end'});});
  if(donutMode){text(svg,el.dataset.orgChartCenter||'Total',{x:cx,y:cy-5,class:'org-chart-center-label','text-anchor':'middle'});text(svg,el.dataset.orgChartValue||String(total),{x:cx,y:cy+22,class:'org-chart-center','text-anchor':'middle'});}
  el.replaceChildren(svg);
}
function pie(el,raw){pieLike(el,raw,false)} function donut(el,raw){
  if(Array.isArray(raw)&&raw.length===1&&typeof raw[0]==='object'&&'max'in raw[0]){
    const value=number(raw[0].value),max=number(raw[0].max)||100;return pieLike(el,[{label:'Concluído',value},{label:'Restante',value:Math.max(0,max-value)}],true);
  } pieLike(el,raw,true);
}
function sparkline(el,raw){
  const {series}=normalize(raw);const values=series[0]?.data||[];if(!values.length)return empty(el);const W=240,H=72,P=6,{min,max,range}=bounds([{data:values}]);const svg=root(el,`0 0 ${W} ${H}`,'Sparkline');
  const pts=values.map((v,i)=>[P+(W-P*2)*(values.length<=1?.5:i/(values.length-1)),P+(H-P*2)-(v-min)/range*(H-P*2)]);svg.append(make('path',{d:pts.map((p,i)=>(i?'L':'M')+p.join(' ')).join(' '),class:'org-chart-series'}));el.classList.add('org-chart-sparkline');el.replaceChildren(svg);
}
function gauge(el,raw){
  const item=Array.isArray(raw)?(raw[0]||{}):raw;const value=number(item.value??item),min=number(item.min??0),max=number(item.max??100)||100,pct=clamp((value-min)/(max-min||1),0,1);const W=360,H=230,cx=180,cy=180,r=120;const svg=root(el,`0 0 ${W} ${H}`,'Gauge');
  const polar=a=>[cx+Math.cos(a)*r,cy+Math.sin(a)*r],start=Math.PI,end=0,[sx,sy]=polar(start),[ex,ey]=polar(end);svg.append(make('path',{d:`M ${sx} ${sy} A ${r} ${r} 0 0 1 ${ex} ${ey}`,class:'org-chart-gauge-track'}));
  const angle=Math.PI*(1-pct),[vx,vy]=polar(angle);svg.append(make('path',{d:`M ${sx} ${sy} A ${r} ${r} 0 ${pct>.5?1:0} 1 ${vx} ${vy}`,class:'org-chart-gauge-value'}));
  const needleR=90,[nx,ny]=[cx+Math.cos(angle)*needleR,cy+Math.sin(angle)*needleR];svg.append(make('line',{x1:cx,y1:cy,x2:nx,y2:ny,class:'org-chart-gauge-needle'}));svg.append(make('circle',{cx,cy,r:7,class:'org-chart-gauge-pin'}));
  text(svg,String(value),{x:cx,y:cy+35,class:'org-chart-gauge-number','text-anchor':'middle'});text(svg,item.unit||el.dataset.orgChartUnit||'',{x:cx,y:cy+55,class:'org-chart-axis','text-anchor':'middle'});text(svg,String(min),{x:45,y:202,class:'org-chart-axis'});text(svg,String(max),{x:315,y:202,class:'org-chart-axis','text-anchor':'end'});el.replaceChildren(svg);
}
function candlestick(el,raw){
  const items=Array.isArray(raw)?raw:(raw?.items||[]);if(!items.length)return empty(el);const W=760,H=340,left=52,right=22,top=20,bottom=45;const svg=root(el,`0 0 ${W} ${H}`,'Gráfico candlestick');
  const lows=items.map(i=>number(i.low)),highs=items.map(i=>number(i.high)),min=Math.min(...lows),max=Math.max(...highs),range=max-min||1,plotW=W-left-right,plotH=H-top-bottom;grid(svg,{W,H,left,right,top,bottom,min,max});const slot=plotW/items.length,body=Math.min(18,slot*.5);
  items.forEach((item,i)=>{const x=left+i*slot+slot/2,y=v=>top+plotH-(number(v)-min)/range*plotH,yo=y(item.open),yc=y(item.close),yh=y(item.high),yl=y(item.low),up=number(item.close)>=number(item.open),klass=up?'is-up':'is-down';svg.append(make('line',{x1:x,y1:yh,x2:x,y2:yl,class:`org-chart-candle-wick ${klass}`}));const rect=make('rect',{x:x-body/2,y:Math.min(yo,yc),width:body,height:Math.max(2,Math.abs(yc-yo)),class:`org-chart-candle ${klass}`});title(rect,`${item.label||''} O:${item.open} H:${item.high} L:${item.low} C:${item.close}`);svg.append(rect);if(i%Math.ceil(items.length/8)===0)text(svg,item.label||`${i+1}`,{x,y:H-16,class:'org-chart-axis','text-anchor':'middle'});});el.replaceChildren(svg);
}
function empty(el){el.innerHTML='<div class="org-chart-empty">Sem dados para exibir.</div>'}
const renderers={line,column,columns:column,bar,bars:bar,pie,donut,sparkline,gauge,candlestick};
function render(el, options={}){
  const raw=options.data ?? parse(el),type=options.type ?? el.dataset.orgChart ?? 'line';el.classList.add('org-chart',`org-chart-${type}`);(renderers[type]||line)(el,raw);el.dispatchEvent(new CustomEvent('organic:chart:render',{bubbles:true,detail:{type,data:raw}}));return el;
}
function renderAll(root=document){root.querySelectorAll('[data-org-chart]').forEach(el=>render(el));}
renderAll();
window.Organic=window.Organic||{};
window.Organic.Chart={render,renderAll,types:Object.keys(renderers)};
