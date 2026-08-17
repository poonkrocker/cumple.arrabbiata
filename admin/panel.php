<?php
/* Panel de moderación de pizzas — protegido por la sesión de admin del sitio.
   Ajustá LOGIN_URL si tu login vive en otra ruta. */
define('LOGIN_URL', '../login.php');   // <-- ruta a tu login.php
@session_set_cookie_params(['lifetime'=>0,'path'=>'/','secure'=>true,'httponly'=>true,'samesite'=>'Lax']);
@session_start();
if (empty($_SESSION['admin_id'])) { header('Location: ' . LOGIN_URL); exit; }
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Panel de pizzas · Arrabbiata</title>
<link rel="icon" href="../favicon.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --rojo:#CC1414; --rojo-d:#A50E0E; --rojo-f:#D73828; --amarillo:#FDB740;
    --blanco:#F8F6F2; --crema:#F0E6D0; --marron:#2b1a0a; --panel:#fff; --panel2:#f7efdf;
    --line:rgba(184,149,90,.38); --line2:rgba(184,149,90,.62); --ink:#2b1a0a; --muted:#7a6a54; --muted2:#9c8f79;
    --fd:'DM Serif Display',Georgia,serif; --fb:'DM Sans',system-ui,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0; font-family:var(--fb); color:var(--ink); background:var(--crema)}
  .top{display:flex; align-items:center; gap:14px; flex-wrap:wrap; padding:16px 22px; background:linear-gradient(180deg,#fffdf8,var(--blanco)); border-bottom:1px solid var(--line)}
  .top h1{font-family:var(--fd); font-weight:400; font-size:23px; margin:0 auto 0 0; color:var(--marron)}
  .top h1 .dot{color:var(--rojo)}
  .btn{height:38px; padding:0 14px; border:1px solid var(--line2); border-radius:10px; background:var(--panel); color:var(--ink);
    font-family:var(--fb); font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px}
  .btn:hover{border-color:var(--rojo)}
  .btn.accent{background:linear-gradient(180deg,var(--rojo-f),var(--rojo)); color:#fff; border-color:transparent}
  .wrap{max-width:1100px; margin:0 auto; padding:22px}
  .stats{display:flex; gap:12px; flex-wrap:wrap; margin-bottom:18px}
  .stat{background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:12px 18px; min-width:120px}
  .stat b{display:block; font-family:var(--fd); font-size:26px; color:var(--marron); line-height:1}
  .stat span{font-size:12px; color:var(--muted)}
  table{width:100%; border-collapse:collapse; background:var(--panel); border:1px solid var(--line); border-radius:12px; overflow:hidden}
  th,td{text-align:left; padding:11px 12px; border-bottom:1px solid var(--line); font-size:13.5px; vertical-align:middle}
  th{background:var(--panel2); font-weight:700; color:var(--marron); font-size:12px; text-transform:uppercase; letter-spacing:.5px}
  tr:last-child td{border-bottom:0}
  tr.hidden-row{opacity:.5}
  .th{width:52px; height:52px; border-radius:8px; background:var(--panel2); object-fit:contain}
  .pn{font-family:var(--fd); font-size:16px; color:var(--marron)}
  .pd{font-size:12px; color:var(--muted); max-width:220px}
  .contact{font-size:12.5px; line-height:1.5}
  .contact a{color:var(--rojo-d); text-decoration:none}
  .votes{font-family:var(--fd); font-size:20px; color:var(--rojo)}
  .vote-adj{display:inline-flex; align-items:center; gap:8px}
  .vote-adj .vnum{min-width:28px; text-align:center; font-family:var(--fd); font-size:20px; color:var(--rojo)}
  .vote-adj button{width:28px; height:28px; border-radius:7px; border:1px solid var(--line2); background:#fff; cursor:pointer;
    font-size:17px; line-height:1; font-weight:700; color:var(--marron); display:inline-flex; align-items:center; justify-content:center; padding:0}
  .vote-adj button:hover{border-color:var(--rojo); background:#fff6f4}
  .vote-adj button:active{transform:translateY(1px)}
  .row-acts{display:flex; gap:6px; flex-wrap:wrap}
  .row-acts button{height:30px; padding:0 10px; border-radius:7px; border:1px solid var(--line2); background:#fff; font-family:var(--fb); font-size:12px; font-weight:600; cursor:pointer}
  .row-acts .hide{color:var(--muted)}
  .row-acts .del{color:var(--rojo)}
  .row-acts button:hover{border-color:var(--rojo)}
  .badge{display:inline-block; font-size:10px; font-weight:700; padding:2px 7px; border-radius:20px; letter-spacing:.5px}
  .badge.win{background:var(--amarillo); color:var(--marron)}
  .badge.hid{background:var(--marron); color:#fff}
  .empty{text-align:center; padding:50px; color:var(--muted2); font-style:italic}
  .toast{position:fixed; left:50%; bottom:24px; transform:translateX(-50%) translateY(16px); opacity:0; background:var(--marron); color:var(--crema); font-size:14px; padding:11px 18px; border-radius:12px; z-index:50; transition:.2s}
  .toast.show{opacity:1; transform:translateX(-50%)}
  @media (max-width:720px){ .hide-sm{display:none} .pd{max-width:120px} }
</style>
</head>
<body>
  <div class="top">
    <h1>Panel de pizzas<span class="dot">.</span></h1>
    <a class="btn" href="ingredientes.php">🧀 Ingredientes</a>
    <a class="btn" href="../index.html" target="_blank">🍕 Ver galería</a>
    <a class="btn accent" href="../api.php?action=admin-csv">⬇ Pizzas (CSV)</a>
    <a class="btn accent" href="../api.php?action=admin-users-csv">👥 Clientes (CSV)</a>
  </div>
  <div class="wrap">
    <div class="stats" id="stats"></div>
    <div id="tableWrap"><div class="empty">Cargando…</div></div>
  </div>
  <div class="toast" id="toast"></div>

<script>
"use strict";
const API='../api.php';
let PIZZAS=[];
function $(id){return document.getElementById(id);}
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
let tt; function toast(m){const t=$('toast');t.textContent=m;t.classList.add('show');clearTimeout(tt);tt=setTimeout(()=>t.classList.remove('show'),2200);}
function fdate(ts){ const d=new Date((ts||0)*1000); return isNaN(d)?'':d.toLocaleDateString('es-AR',{day:'2-digit',month:'2-digit'})+' '+d.toLocaleTimeString('es-AR',{hour:'2-digit',minute:'2-digit'}); }

async function load(){
  try{
    const r=await fetch(API+'?action=admin-list',{credentials:'same-origin'});
    if(r.status===401){ location.href='<?php echo htmlspecialchars(LOGIN_URL, ENT_QUOTES); ?>'; return; }
    const d=await r.json();
    if(!d.ok){ $('tableWrap').innerHTML='<div class="empty">'+(d.error||'Error')+'</div>'; return; }
    PIZZAS=d.pizzas||[]; render();
  }catch(e){ $('tableWrap').innerHTML='<div class="empty">Sin conexión con el servidor</div>'; }
}

function render(){
  const total=PIZZAS.length;
  const votes=PIZZAS.reduce((s,p)=>s+(p.votes||0),0);
  const visible=PIZZAS.filter(p=>!p.hidden).length;
  $('stats').innerHTML=
    '<div class="stat"><b>'+total+'</b><span>pizzas</span></div>'+
    '<div class="stat"><b>'+visible+'</b><span>visibles</span></div>'+
    '<div class="stat"><b>'+votes+'</b><span>votos totales</span></div>';
  if(!total){ $('tableWrap').innerHTML='<div class="empty">Todavía no hay pizzas guardadas.</div>'; return; }
  // orden por votos ya viene del servidor
  let rows='';
  PIZZAS.forEach((p,i)=>{
    const ig = p.instagram ? '<a href="https://instagram.com/'+esc(p.instagram)+'" target="_blank">@'+esc(p.instagram)+'</a>' : '';
    const ph = p.phone ? '<a href="https://wa.me/'+esc(p.phone.replace(/[^0-9]/g,''))+'" target="_blank">'+esc(p.phone)+'</a>' : '';
    const contact=[ph,ig].filter(Boolean).join('<br>')||'<span style="color:var(--muted2)">—</span>';
    const win = (i===0 && p.votes>0 && !p.hidden) ? ' <span class="badge win">👑 1°</span>' : '';
    const hid = p.hidden ? ' <span class="badge hid">oculta</span>' : '';
    rows+=
      '<tr class="'+(p.hidden?'hidden-row':'')+'">'+
        '<td class="hide-sm"><img class="th" src="'+(p.thumb||'')+'" alt=""></td>'+
        '<td><div class="pn">'+esc(p.name)+win+hid+'</div><div class="pd">'+esc(p.description||'')+'</div></td>'+
        '<td>'+esc(p.author||'—')+'</td>'+
        '<td class="contact">'+contact+'</td>'+
        '<td class="votes"><div class="vote-adj">'+
          '<button data-a="vminus" data-id="'+esc(p.id)+'" title="Restar un voto">−</button>'+
          '<span class="vnum">'+(p.votes||0)+'</span>'+
          '<button data-a="vplus" data-id="'+esc(p.id)+'" title="Sumar un voto">+</button>'+
        '</div></td>'+
        '<td class="hide-sm">'+fdate(p.createdAt)+'</td>'+
        '<td><div class="row-acts">'+
          '<button class="hide" data-a="hide" data-id="'+esc(p.id)+'">'+(p.hidden?'Mostrar':'Ocultar')+'</button>'+
          '<button class="del" data-a="del" data-id="'+esc(p.id)+'" data-n="'+esc(p.name)+'">Borrar</button>'+
        '</div></td>'+
      '</tr>';
  });
  $('tableWrap').innerHTML=
    '<table><thead><tr><th class="hide-sm"></th><th>Pizza</th><th>Autor</th><th>Contacto</th><th>Votos</th><th class="hide-sm">Fecha</th><th>Acciones</th></tr></thead><tbody>'+rows+'</tbody></table>';
  $('tableWrap').querySelectorAll('button[data-a]').forEach(b=>{
    b.onclick=()=>{ const id=b.dataset.id;
      if(b.dataset.a==='hide') toggleHide(id);
      else if(b.dataset.a==='vplus') adjustVotes(id, 1);
      else if(b.dataset.a==='vminus') adjustVotes(id, -1);
      else del(id, b.dataset.n); };
  });
}

async function adjustVotes(id, delta){
  try{ const r=await fetch(API+'?action=admin-adjust-votes&id='+encodeURIComponent(id)+'&delta='+delta,{method:'POST',credentials:'same-origin'});
    if(r.status===401){ location.href='<?php echo htmlspecialchars(LOGIN_URL, ENT_QUOTES); ?>'; return; }
    const d=await r.json();
    if(d.ok){
      const p=PIZZAS.find(x=>x.id===id); if(p) p.votes=d.votes;
      // actualizamos el número y el total sin reordenar filas (para no marear al tocar +/-)
      const cell=document.querySelector('.vote-adj button[data-id="'+CSS.escape(id)+'"]');
      if(cell){ const num=cell.parentNode.querySelector('.vnum'); if(num) num.textContent=d.votes; }
      $('stats').querySelector('.stat:nth-child(3) b').textContent=PIZZAS.reduce((s,x)=>s+(x.votes||0),0);
    } else toast(d.error||'No se pudo'); }catch(e){ toast('Sin conexión'); }
}

async function toggleHide(id){
  try{ const r=await fetch(API+'?action=admin-toggle-hidden&id='+encodeURIComponent(id),{method:'POST',credentials:'same-origin'});
    const d=await r.json();
    if(d.ok){ const p=PIZZAS.find(x=>x.id===id); if(p) p.hidden=d.hidden; render(); toast(d.hidden?'Pizza ocultada':'Pizza visible'); }
    else toast(d.error||'No se pudo'); }catch(e){ toast('Sin conexión'); }
}
async function del(id,name){
  if(!confirm('¿Borrar "'+name+'" para siempre? No se puede deshacer.')) return;
  try{ const r=await fetch(API+'?action=admin-delete&id='+encodeURIComponent(id),{method:'POST',credentials:'same-origin'});
    const d=await r.json();
    if(d.ok){ PIZZAS=PIZZAS.filter(x=>x.id!==id); render(); toast('Pizza borrada'); }
    else toast(d.error||'No se pudo'); }catch(e){ toast('Sin conexión'); }
}
load();
</script>
</body>
</html>