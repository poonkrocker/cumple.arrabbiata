<?php
/* perfil.php — Perfil del usuario del concurso.
   Muestra sus pizzas guardadas y le deja elegir con cuáles participa
   (hasta el máximo configurado). Protegido por la sesión de usuario. */
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
if (empty($_SESSION['user_id'])) { header('Location: cuenta.php'); exit; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mi perfil · Pizzas Arrabbiata</title>
<link rel="icon" href="favicon.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --rojo:#CC1414; --rojo-d:#A50E0E; --rojo-f:#D73828; --amarillo:#FDB740; --verde:#2e7d32;
    --blanco:#F8F6F2; --crema:#F0E6D0; --marron:#2b1a0a; --panel:#fff; --panel2:#f7efdf;
    --line:rgba(184,149,90,.38); --line2:rgba(184,149,90,.62); --ink:#2b1a0a; --muted:#7a6a54; --muted2:#9c8f79;
    --fd:'DM Serif Display',Georgia,serif; --fb:'DM Sans',system-ui,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0; font-family:var(--fb); color:var(--ink); background:var(--crema)}
  .top{display:flex; align-items:center; gap:12px; flex-wrap:wrap; padding:16px 20px;
    background:linear-gradient(180deg,#fffdf8,var(--blanco)); border-bottom:1px solid var(--line)}
  .top h1{font-family:var(--fd); font-weight:400; font-size:22px; margin:0 auto 0 0; color:var(--marron)}
  .top h1 .dot{color:var(--rojo)}
  .btn{height:38px; padding:0 14px; border:1px solid var(--line2); border-radius:10px; background:var(--panel); color:var(--ink);
    font-family:var(--fb); font-size:13px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:6px}
  .btn:hover{border-color:var(--rojo)}
  .btn.accent{background:linear-gradient(180deg,var(--rojo-f),var(--rojo)); color:#fff; border-color:transparent}
  .wrap{max-width:1000px; margin:0 auto; padding:20px}
  .counter{background:var(--panel); border:1px solid var(--line); border-radius:12px; padding:13px 16px; margin-bottom:16px; font-size:14px}
  .counter b{font-family:var(--fd); font-size:19px; color:var(--rojo)}
  .grid{display:grid; gap:14px; grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}
  .p{background:var(--panel); border:1px solid var(--line); border-radius:14px; overflow:hidden; display:flex; flex-direction:column}
  .p .thumb{aspect-ratio:1/1; background:var(--panel2); display:flex; align-items:center; justify-content:center}
  .p .thumb img{width:100%; height:100%; object-fit:contain}
  .p .body{padding:11px 13px; display:flex; flex-direction:column; gap:8px; flex:1}
  .p .nm{font-family:var(--fd); font-size:17px; color:var(--marron); line-height:1.15}
  .p .meta{font-size:12px; color:var(--muted)}
  .p .state{font-size:12px; font-weight:700; display:inline-flex; align-items:center; gap:5px}
  .p .state.in{color:var(--verde)} .p .state.out{color:var(--muted2)}
  .p .acts{margin-top:auto; display:flex; gap:7px; flex-wrap:wrap}
  .p .acts button{flex:1; height:34px; border-radius:8px; border:1px solid var(--line2); background:#fff; cursor:pointer;
    font-family:var(--fb); font-size:12.5px; font-weight:600; color:var(--ink)}
  .p .acts button.on{background:linear-gradient(180deg,var(--rojo-f),var(--rojo)); color:#fff; border-color:transparent}
  .p .acts button:hover{border-color:var(--rojo)}
  .p.hidden-admin{opacity:.6}
  .warn{font-size:11.5px; color:var(--rojo-d)}
  .empty{text-align:center; padding:44px 20px; color:var(--muted2)}
  .empty a{color:var(--rojo-d); font-weight:600; text-decoration:none}
  /* datos de perfil */
  .profile{background:var(--panel); border:1px solid var(--line); border-radius:14px; padding:16px; margin-bottom:18px}
  .profile h2{font-family:var(--fd); font-weight:400; font-size:18px; margin:0 0 10px; color:var(--marron)}
  .frow{display:flex; gap:12px; flex-wrap:wrap}
  .frow>div{flex:1; min-width:150px}
  .profile label{display:block; font-size:11.5px; font-weight:600; color:var(--muted); margin-bottom:4px; text-transform:uppercase; letter-spacing:.5px}
  .profile input, .profile select{width:100%; height:38px; padding:0 11px; border:1px solid var(--line2); border-radius:9px; font-size:14px; font-family:inherit; color:var(--ink); background:#fff}
  .profile input:focus, .profile select:focus{outline:none; border-color:var(--rojo); box-shadow:0 0 0 3px rgba(204,20,20,.12)}
  .profile .save{margin-top:12px}
  .toast{position:fixed; left:50%; bottom:22px; transform:translateX(-50%) translateY(16px); opacity:0; background:var(--marron); color:var(--crema); font-size:14px; padding:11px 18px; border-radius:12px; z-index:50; transition:.2s}
  .toast.show{opacity:1; transform:translateX(-50%)}
</style>
</head>
<body>
  <div class="top">
    <h1>Mi perfil<span class="dot">.</span></h1>
    <a class="btn accent" href="editor.html">🍕 Armar una pizza</a>
    <a class="btn" href="index.html">Galería</a>
    <button class="btn" id="btnLogout">Salir</button>
  </div>

  <div class="wrap">
    <div class="profile">
      <h2 id="hello">Tus datos</h2>
      <div class="frow">
        <div><label>Nombre visible</label><input type="text" id="pDisplay" maxlength="60" placeholder="Cómo aparecés en la galería"></div>
        <div><label>Email</label><input type="email" id="pEmail" maxlength="120" placeholder="tu@email.com"></div>
        <div>
          <label>Pizza favorita</label>
          <select id="pFav">
            <option value="">Elegí una…</option>
            <option>Margherita</option><option>Cipollina</option><option>Genovesa</option>
            <option>Pineta</option><option>Romerina</option><option>Quattro</option>
            <option>Pucheta</option><option>Especial</option><option>Arrabbiata</option>
          </select>
        </div>
      </div>
      <div class="frow" style="margin-top:10px">
        <div><label>Teléfono (opcional)</label><input type="tel" id="pPhone" maxlength="40" placeholder="Por si ganás"></div>
        <div><label>Instagram (opcional)</label><input type="text" id="pIg" maxlength="40" placeholder="@vos"></div>
      </div>
      <button class="btn accent save" id="btnSaveProfile">Guardar datos</button>
    </div>

    <div class="counter" id="counter">Cargando…</div>
    <div id="grid"><div class="empty">Cargando tus pizzas…</div></div>
  </div>
  <div class="toast" id="toast"></div>

<script>
"use strict";
const API='api.php';
let MAX=5, ENTERED=0, PIZZAS=[];
function $(id){return document.getElementById(id);}
function esc(s){return String(s==null?'':s).replace(/[&<>"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));}
let tt; function toast(m){const t=$('toast');t.textContent=m;t.classList.add('show');clearTimeout(tt);tt=setTimeout(()=>t.classList.remove('show'),2200);}

async function api(action, opts){
  const r=await fetch(API+'?action='+action, Object.assign({credentials:'same-origin'}, opts||{}));
  if(r.status===401){ location.href='cuenta.php'; return {ok:false,error:'Iniciá sesión'}; }
  let d=null; try{ d=await r.json(); }catch(e){}
  return d||{ok:false,error:'Sin conexión'};
}

async function loadMe(){
  const d=await api('me');
  if(d.ok && d.user){
    $('hello').textContent='Hola, '+(d.user.displayName||d.user.username);
    $('pDisplay').value=d.user.displayName||'';
    $('pEmail').value=d.user.email||'';
    $('pFav').value=d.user.favPizza||'';
    $('pPhone').value=d.user.phone||'';
    $('pIg').value=d.user.instagram||'';
  } else { location.href='cuenta.php'; }
}

async function loadPizzas(){
  const d=await api('my-pizzas');
  if(!d.ok){ $('grid').innerHTML='<div class="empty">'+(d.error||'Error')+'</div>'; return; }
  PIZZAS=d.pizzas||[]; MAX=d.maxEntries||5; ENTERED=d.enteredCount||0;
  render();
}

function render(){
  $('counter').innerHTML='Participás con <b>'+ENTERED+'</b> de <b>'+MAX+'</b> pizzas permitidas en el concurso. '+
    (ENTERED>=MAX ? 'Llegaste al máximo: para sumar otra, retirá una.' : 'Podés sumar '+(MAX-ENTERED)+' más.');
  if(!PIZZAS.length){
    $('grid').innerHTML='<div class="empty">Todavía no guardaste ninguna pizza.<br><br><a href="editor.html">Armá tu primera pizza →</a></div>';
    return;
  }
  let html='';
  PIZZAS.forEach(p=>{
    const inContest=!!p.entered;
    const canEnter = inContest || ENTERED<MAX;
    const btnLabel = inContest ? 'Retirar del concurso' : (canEnter?'Participar':'Máximo alcanzado');
    html+=
      '<div class="p'+(p.hiddenByAdmin?' hidden-admin':'')+'">'+
        '<div class="thumb"><img src="'+(p.thumb||'')+'" alt=""></div>'+
        '<div class="body">'+
          '<div class="nm">'+esc(p.name)+'</div>'+
          '<div class="meta">'+(p.count||0)+' ingredientes'+(inContest?(' · '+(p.votes||0)+' votos'):'')+'</div>'+
          '<div class="state '+(inContest?'in':'out')+'">'+(inContest?'✓ En el concurso':'Guardada (no participa)')+'</div>'+
          (p.hiddenByAdmin?'<div class="warn">La organización ocultó esta pizza.</div>':'')+
          '<div class="acts">'+
            '<button data-id="'+esc(p.id)+'" data-want="'+(inContest?'0':'1')+'" class="'+(inContest?'':'on')+'"'+((!inContest&&!canEnter)?' disabled':'')+'>'+btnLabel+'</button>'+
          '</div>'+
        '</div>'+
      '</div>';
  });
  $('grid').innerHTML=html;
  $('grid').querySelectorAll('button[data-id]').forEach(b=>{
    b.onclick=()=>toggleEntered(b.dataset.id, b.dataset.want==='1');
  });
}

async function toggleEntered(id, want){
  const d=await api('toggle-entered',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({id,entered:want})});
  if(d.ok){
    const p=PIZZAS.find(x=>x.id===id); if(p) p.entered=d.entered;
    ENTERED=PIZZAS.filter(x=>x.entered).length;
    render();
    toast(d.entered?'¡Ya participa! 🍕':'Retirada del concurso');
  } else { toast(d.error||'No se pudo'); }
}

$('btnSaveProfile').onclick=async()=>{
  const email=$('pEmail').value.trim(), favPizza=$('pFav').value;
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ toast('Poné un email válido'); return; }
  if(!favPizza){ toast('Elegí tu pizza favorita'); return; }
  const d=await api('update-profile',{method:'POST',headers:{'Content-Type':'application/json'},
    body:JSON.stringify({displayName:$('pDisplay').value.trim(), email, favPizza,
      phone:$('pPhone').value.trim(), instagram:$('pIg').value.trim()})});
  if(d.ok){ toast('Datos guardados'); if(d.user) $('hello').textContent='Hola, '+(d.user.displayName||d.user.username); }
  else toast(d.error||'No se pudo');
};

$('btnLogout').onclick=async()=>{
  await api('user-logout',{method:'POST'});
  location.href='index.html';
};

loadMe();
loadPizzas();
</script>
</body>
</html>
