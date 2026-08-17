<?php
/* cuenta.php — Ingreso y registro de usuarios del concurso de pizzas.
   No es el login de admin (ese sigue siendo login.php). Este es para la gente
   que quiere guardar sus pizzas y elegir con cuáles participa.
   Usa las acciones del api.php: register / user-login. */
session_set_cookie_params([
  'lifetime' => 0, 'path' => '/', 'secure' => true, 'httponly' => true, 'samesite' => 'Lax',
]);
session_start();
// si ya hay sesión de usuario, al perfil directo
if (!empty($_SESSION['user_id'])) { header('Location: perfil.php'); exit; }
?>
<!doctype html>
<html lang="es">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Mi cuenta · Pizzas Arrabbiata</title>
<link rel="icon" href="favicon.png">
<link href="https://fonts.googleapis.com/css2?family=DM+Serif+Display&family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root{
    --rojo:#CC1414; --rojo-d:#A50E0E; --rojo-f:#D73828; --amarillo:#FDB740;
    --blanco:#F8F6F2; --crema:#F0E6D0; --marron:#2b1a0a; --panel:#fff;
    --line:rgba(184,149,90,.38); --line2:rgba(184,149,90,.62); --ink:#2b1a0a; --muted:#7a6a54;
    --fd:'DM Serif Display',Georgia,serif; --fb:'DM Sans',system-ui,sans-serif;
  }
  *{box-sizing:border-box}
  body{margin:0; font-family:var(--fb); color:var(--ink);
    background:radial-gradient(120% 90% at 80% 0%,rgba(212,180,131,.45),transparent 55%),var(--crema);
    min-height:100vh; display:flex; align-items:center; justify-content:center; padding:20px}
  .card{background:var(--panel); border:1px solid var(--line2); border-radius:16px;
    box-shadow:0 14px 34px rgba(43,26,10,.16); width:min(380px,94vw); padding:26px 24px}
  h1{font-family:var(--fd); font-weight:400; font-size:25px; margin:0 0 3px; color:var(--marron)}
  h1 .dot{color:var(--rojo)}
  .sub{color:var(--muted); font-size:13px; margin:0 0 18px}
  .tabs{display:flex; gap:6px; margin-bottom:16px; background:#f2e8d3; padding:4px; border-radius:11px}
  .tabs button{flex:1; height:36px; border:none; border-radius:8px; background:transparent; cursor:pointer;
    font-family:var(--fb); font-size:13.5px; font-weight:600; color:var(--muted)}
  .tabs button.sel{background:#fff; color:var(--marron); box-shadow:0 2px 6px rgba(120,90,40,.14)}
  label{display:block; font-size:12px; font-weight:600; color:var(--muted); margin:11px 0 5px; text-transform:uppercase; letter-spacing:.5px}
  input, select{width:100%; padding:11px; border:1px solid var(--line2); border-radius:9px; box-sizing:border-box; font-size:15px; font-family:inherit; color:var(--ink); background:#fff}
  input:focus, select:focus{outline:none; border-color:var(--rojo); box-shadow:0 0 0 3px rgba(204,20,20,.12)}
  .hint{font-size:11.5px; color:var(--muted); margin-top:4px}
  button.go{background:linear-gradient(180deg,var(--rojo-f),var(--rojo)); color:#fff; padding:12px; border:none;
    border-radius:9px; cursor:pointer; width:100%; font-size:15px; font-weight:600; margin-top:18px; font-family:inherit}
  button.go:hover{background:var(--rojo-d)} button.go:disabled{opacity:.6; cursor:default}
  .error{color:var(--rojo-d); font-size:13.5px; margin-top:12px; min-height:1px}
  .foot{margin-top:16px; text-align:center; font-size:13px}
  .foot a{color:var(--rojo-d); text-decoration:none}
  .row2{display:flex; gap:10px} .row2>div{flex:1}
  form{display:none} form.on{display:block}
</style>
</head>
<body>
  <div class="card">
    <h1>Tu cuenta<span class="dot">.</span></h1>
    <p class="sub">Guardá tus pizzas y elegí con cuáles participás del concurso.</p>

    <div class="tabs">
      <button id="tabLogin" class="sel" type="button">Ingresar</button>
      <button id="tabReg" type="button">Crear cuenta</button>
    </div>

    <!-- INGRESAR -->
    <form id="formLogin" class="on" autocomplete="on">
      <label for="lUser">Usuario</label>
      <input type="text" id="lUser" autocomplete="username" maxlength="40" placeholder="tu usuario">
      <label for="lPass">Contraseña</label>
      <input type="password" id="lPass" autocomplete="current-password" placeholder="••••••••">
      <button class="go" type="submit">Ingresar</button>
      <p class="error" id="loginErr"></p>
    </form>

    <!-- CREAR CUENTA -->
    <form id="formReg" autocomplete="on">
      <label for="rUser">Usuario</label>
      <input type="text" id="rUser" autocomplete="username" maxlength="40" placeholder="cómo querés que te llamen">
      <p class="hint">Al menos 3 caracteres: letras, números y . _ -</p>
      <label for="rPass">Contraseña</label>
      <input type="password" id="rPass" autocomplete="new-password" placeholder="al menos 6 caracteres">
      <label for="rEmail">Email</label>
      <input type="email" id="rEmail" autocomplete="email" maxlength="120" placeholder="tu@email.com">
      <div class="row2">
        <div>
          <label for="rPhone">Teléfono <span style="text-transform:none">(opcional)</span></label>
          <input type="tel" id="rPhone" maxlength="40" placeholder="por si ganás">
        </div>
        <div>
          <label for="rIg">Instagram <span style="text-transform:none">(opcional)</span></label>
          <input type="text" id="rIg" maxlength="40" placeholder="@vos">
        </div>
      </div>
      <label for="rFav">Tu pizza favorita de la carta</label>
      <select id="rFav">
        <option value="">Elegí una…</option>
        <option>Margherita</option>
        <option>Cipollina</option>
        <option>Genovesa</option>
        <option>Pineta</option>
        <option>Romerina</option>
        <option>Quattro</option>
        <option>Pucheta</option>
        <option>Especial</option>
        <option>Arrabbiata</option>
      </select>
      <button class="go" type="submit">Crear cuenta</button>
      <p class="error" id="regErr"></p>
    </form>

    <div class="foot"><a href="index.html">← Volver a la galería</a></div>
  </div>

<script>
"use strict";
const API='api.php';
function $(id){return document.getElementById(id);}
function show(which){
  const login = which==='login';
  $('formLogin').classList.toggle('on', login);
  $('formReg').classList.toggle('on', !login);
  $('tabLogin').classList.toggle('sel', login);
  $('tabReg').classList.toggle('sel', !login);
}
$('tabLogin').onclick=()=>show('login');
$('tabReg').onclick=()=>show('reg');

async function post(action, body){
  const r=await fetch(API+'?action='+action,{method:'POST',credentials:'same-origin',
    headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
  let d=null; try{ d=await r.json(); }catch(e){}
  return d||{ok:false,error:'Sin conexión con el servidor'};
}

$('formLogin').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const btn=$('formLogin').querySelector('.go'); const err=$('loginErr'); err.textContent='';
  const username=$('lUser').value.trim(), password=$('lPass').value;
  if(!username||!password){ err.textContent='Completá usuario y contraseña.'; return; }
  btn.disabled=true; btn.textContent='Ingresando…';
  const d=await post('user-login',{username,password});
  btn.disabled=false; btn.textContent='Ingresar';
  if(d.ok){ location.href='perfil.php'; } else { err.textContent=d.error||'No se pudo ingresar.'; }
});

$('formReg').addEventListener('submit', async (e)=>{
  e.preventDefault();
  const btn=$('formReg').querySelector('.go'); const err=$('regErr'); err.textContent='';
  const username=$('rUser').value.trim(), password=$('rPass').value;
  const email=$('rEmail').value.trim();
  const phone=$('rPhone').value.trim(), instagram=$('rIg').value.trim();
  const favPizza=$('rFav').value;
  if(username.length<3){ err.textContent='El usuario necesita al menos 3 caracteres.'; return; }
  if(password.length<6){ err.textContent='La contraseña necesita al menos 6 caracteres.'; return; }
  if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){ err.textContent='Poné un email válido.'; return; }
  if(!favPizza){ err.textContent='Elegí tu pizza favorita de la carta.'; return; }
  btn.disabled=true; btn.textContent='Creando…';
  const d=await post('register',{username,password,email,phone,instagram,favPizza,displayName:username});
  btn.disabled=false; btn.textContent='Crear cuenta';
  if(d.ok){ location.href='perfil.php'; } else { err.textContent=d.error||'No se pudo crear la cuenta.'; }
});
</script>
</body>
</html>
