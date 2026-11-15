import * as THREE from 'three';
import * as CANNON from 'cannon-es';
import { STR } from './strings.js';

/* ---------- Data (every number has its origin in SOURCES in strings.js) ---------- */
const LIFE_YEARS = 13.1; // McMillan 2024 (PetPrep S54)
const PROFILES = {
  adult: { meals: 2, walkMin: 90, walks: 2, poops: 2, trainMin: 5, takeOuts: 0, groomWeek: 1 },
  puppy: { meals: 4, walkMin: 20, walks: 2, poops: 2, trainMin: 5, takeOuts: 7, groomWeek: 1 },
};
const STEPS_PER_MIN = 100;
const COST = { monthly: 116, setup: 560 }; // PDSA large dog, minimum (GBP)
// ECB euro reference rates, 13 November 2026: 1 EUR = 0.83559 GBP = 1.1052 USD. Fixed on purpose.
const RATES = { GBP: 1, EUR: 1 / 0.83559, USD: 1.1052 / 0.83559 };
const SYMBOL = { GBP: '£', EUR: '€', USD: '$' };
const SCALES = { day: 1, week: 7, year: 365.25, life: 365.25 * LIFE_YEARS };

const state = { lang: 'sl', cur: 'EUR', stage: 'adult', step: 'intro', scale: 'day', monthly: COST.monthly, setup: COST.setup, played: false };
const $ = (s) => document.querySelector(s);
const t = (k) => STR[state.lang][k] ?? k;
const nf = (n, d = 0) => new Intl.NumberFormat(state.lang === 'sl' ? 'sl-SI' : 'en-GB', { maximumFractionDigits: d }).format(n);
const conv = (gbp) => gbp * RATES[state.cur];
const fmtCur = (v, d = 0) => (state.lang === 'sl' ? `${nf(v, d)} ${SYMBOL[state.cur]}` : `${SYMBOL[state.cur]}${nf(v, d)}`);
// Amounts are kept in GBP (the PDSA source currency); other currencies are converted and marked with "~".
const money = (gbp, d = 0, round = 1) => (state.cur === 'GBP' ? '' : '~') + fmtCur(Math.round(conv(gbp) / round) * round || conv(gbp), d);
const reduceMotion = matchMedia('(prefers-reduced-motion: reduce)').matches;

function buildDay(stage) {
  const p = PROFILES[stage];
  const ev = [];
  if (stage === 'adult') {
    ev.push({ at: 420, dur: 10, type: 'meal' }, { at: 450, dur: 45, type: 'walk' }, { at: 500, dur: 5, type: 'poop' },
      { at: 990, dur: 5, type: 'train' }, { at: 1050, dur: 10, type: 'meal' }, { at: 1080, dur: 45, type: 'walk' }, { at: 1130, dur: 5, type: 'poop' });
  } else {
    [420, 540, 660, 780, 900, 1020, 1140].forEach((m, i) => {
      ev.push({ at: m, dur: 5, type: 'out' });
      if (i % 2 === 0) ev.push({ at: m + 10, dur: 10, type: 'meal' });
    });
    ev.push({ at: 570, dur: 10, type: 'walk' }, { at: 585, dur: 5, type: 'poop' }, { at: 960, dur: 10, type: 'walk' }, { at: 975, dur: 5, type: 'poop' }, { at: 1000, dur: 5, type: 'train' });
    ev.sort((a, b) => a.at - b.at);
  }
  return { p, ev };
}

function totals(stage, days) {
  const p = PROFILES[stage];
  return {
    meals: p.meals * days, poops: p.poops * days, walks: p.walks * days, takeOuts: p.takeOuts * days,
    walkMin: p.walkMin * days, steps: p.walkMin * STEPS_PER_MIN * days, trainMin: p.trainMin * days,
    groom: (p.groomWeek * days) / 7, tasks: (p.meals + p.poops + p.walks + p.takeOuts + 1) * days,
  };
}

/* ---------- Scene ---------- */
const canvas = $('#scene');
const renderer = new THREE.WebGLRenderer({ canvas, antialias: true });
renderer.setPixelRatio(Math.min(devicePixelRatio, 2));
renderer.shadowMap.enabled = true;
renderer.shadowMap.type = THREE.PCFSoftShadowMap;
const scene = new THREE.Scene();
const SKY_DAY = new THREE.Color('#F3F5F2'), SKY_EVE = new THREE.Color('#DDE6E0');
scene.background = SKY_DAY.clone();
const camera = new THREE.PerspectiveCamera(36, 1, 0.1, 100);
const C = { graphite: '#121614', mist: '#F3F5F2', mint: '#7FE0B4', mintText: '#1A7A55', dog: '#E7C169', dogDark: '#CFA24A', gold: '#FFD15C', path: '#E3E8E3' };
const mat = (color, o = {}) => new THREE.MeshStandardMaterial({ color, roughness: 0.75, ...o });

scene.add(new THREE.HemisphereLight('#ffffff', '#cfd8d1', 1.15));
const sunLight = new THREE.DirectionalLight('#ffffff', 1.6);
sunLight.castShadow = true;
sunLight.shadow.mapSize.set(1024, 1024);
Object.assign(sunLight.shadow.camera, { left: -9, right: 11, top: 9, bottom: -9, near: 1, far: 40 });
scene.add(sunLight);

const add = (geo, material, pos, parent = scene, shadow = true) => {
  const m = new THREE.Mesh(geo, material);
  m.position.set(...pos); m.castShadow = shadow; m.receiveShadow = true; parent.add(m); return m;
};
// Platform: home in the middle, walking path around it
add(new THREE.CylinderGeometry(4.3, 4.5, 0.5, 64), mat(C.graphite), [0, -0.25, 0], scene, false);
add(new THREE.CylinderGeometry(4.2, 4.2, 0.06, 64), mat('#ffffff'), [0, 0.03, 0], scene, false);
const ring = add(new THREE.RingGeometry(2.75, 3.65, 64), mat(C.path), [0, 0.065, 0], scene, false);
ring.rotation.x = -Math.PI / 2;
add(new THREE.CylinderGeometry(1.5, 1.5, 0.04, 48), mat(C.mint, { roughness: 0.9 }), [0, 0.08, 0], scene, false); // rug
// Bowl, bed, door mat
const BOWL = new THREE.Vector3(-0.95, 0, 0.55), HOME = new THREE.Vector3(0.2, 0, -0.1), DOOR = new THREE.Vector3(0, 0, 2.2);
add(new THREE.CylinderGeometry(0.3, 0.22, 0.16, 24), mat(C.graphite), [BOWL.x, 0.18, BOWL.z]);
const kibble = add(new THREE.CylinderGeometry(0.24, 0.24, 0.04, 24), mat('#B9824B'), [BOWL.x, 0.27, BOWL.z], scene, false);
kibble.visible = false;
add(new THREE.CylinderGeometry(0.7, 0.75, 0.16, 32), mat('#2A322E'), [1.0, 0.18, -0.9]);
add(new THREE.BoxGeometry(1.0, 0.03, 0.5), mat(C.graphite), [DOOR.x, 0.1, DOOR.z], scene, false);
// Trees along the path
[[3.95, -1.2], [-3.9, 1.3], [-2.2, -3.5], [1.6, 3.75]].forEach(([x, z], i) => {
  add(new THREE.CylinderGeometry(0.07, 0.09, 0.5, 8), mat('#6B4F3A'), [x, 0.3, z]);
  add(new THREE.ConeGeometry(0.42, 1.0 + (i % 2) * 0.3, 10), mat(C.mintText), [x, 1.0 + (i % 2) * 0.15, z]);
});
// Sun
const sun = add(new THREE.SphereGeometry(0.42, 24, 16), new THREE.MeshBasicMaterial({ color: C.gold }), [0, 6, -6], scene, false);

/* ---------- Dog (toy figure, deliberately not realistic) ---------- */
const dog = new THREE.Group(); scene.add(dog);
const dBody = new THREE.Group(); dog.add(dBody);
const body = add(new THREE.CapsuleGeometry(0.3, 0.75, 8, 16), mat(C.dog), [0, 0.72, 0], dBody); body.rotation.x = Math.PI / 2;
const head = new THREE.Group(); head.position.set(0, 1.02, 0.62); dBody.add(head);
add(new THREE.SphereGeometry(0.29, 20, 16), mat(C.dog), [0, 0, 0], head);
add(new THREE.BoxGeometry(0.26, 0.2, 0.3), mat(C.dog), [0, -0.07, 0.27], head);
add(new THREE.SphereGeometry(0.06, 12, 8), mat(C.graphite), [0, -0.02, 0.43], head);
[-1, 1].forEach((s) => {
  const ear = add(new THREE.SphereGeometry(0.15, 12, 10), mat(C.dogDark), [0.25 * s, -0.02, -0.02], head); ear.scale.set(0.45, 1.25, 0.9);
  add(new THREE.SphereGeometry(0.04, 10, 8), mat(C.graphite), [0.12 * s, 0.07, 0.25], head, false);
});
const collar = add(new THREE.TorusGeometry(0.27, 0.05, 8, 24), mat(C.mint), [0, 0.86, 0.42], dBody); collar.rotation.x = Math.PI / 2.6;
const legs = [[-0.18, 0.38], [0.18, 0.38], [-0.18, -0.38], [0.18, -0.38]].map(([x, z]) => {
  const g = new THREE.Group(); g.position.set(x, 0.55, z); dBody.add(g);
  add(new THREE.CapsuleGeometry(0.085, 0.36, 4, 10), mat(C.dog), [0, -0.24, 0], g); return g;
});
const tail = new THREE.Group(); tail.position.set(0, 0.86, -0.6); dBody.add(tail);
const tailM = add(new THREE.ConeGeometry(0.075, 0.42, 10), mat(C.dog), [0, 0.02, -0.2], tail); tailM.rotation.x = -1.45;
dog.position.copy(HOME);
const dogAnim = { mode: 'idle', gait: 0 };

/* ---------- Token bin with physics ---------- */
const BIN = { x: 6.6, z: 0, half: 1.25, h: 3.4 };
add(new THREE.BoxGeometry(BIN.half * 2 + 0.3, 0.5, BIN.half * 2 + 0.3), mat(C.graphite), [BIN.x, -0.25, BIN.z], scene, false);
add(new THREE.BoxGeometry(BIN.half * 2, 0.04, BIN.half * 2), mat('#ffffff'), [BIN.x, 0.02, BIN.z], scene, false);
const glass = new THREE.MeshStandardMaterial({ color: '#ffffff', transparent: true, opacity: 0.16, roughness: 0.1, depthWrite: false });
[[1, 0], [-1, 0], [0, 1], [0, -1]].forEach(([dx, dz]) => {
  const w = add(new THREE.BoxGeometry(dx ? 0.04 : BIN.half * 2, BIN.h, dz ? 0.04 : BIN.half * 2), glass, [BIN.x + dx * BIN.half, BIN.h / 2, BIN.z + dz * BIN.half], scene, false);
  w.receiveShadow = false;
});
const world = new CANNON.World({ gravity: new CANNON.Vec3(0, -18, 0) });
world.allowSleep = true;
world.defaultContactMaterial.friction = 0.5; world.defaultContactMaterial.restitution = 0.15;
const ground = new CANNON.Body({ mass: 0, shape: new CANNON.Plane() });
ground.quaternion.setFromEuler(-Math.PI / 2, 0, 0); ground.position.y = 0.04; world.addBody(ground);
[[1, 0, -Math.PI / 2], [-1, 0, Math.PI / 2], [0, 1, Math.PI], [0, -1, 0]].forEach(([dx, dz, ry]) => {
  const b = new CANNON.Body({ mass: 0, shape: new CANNON.Plane() });
  b.quaternion.setFromEuler(0, ry, 0); b.position.set(BIN.x + dx * BIN.half, 0, BIN.z + dz * BIN.half); world.addBody(b);
});
const TOKENS = {
  meal: { geo: new THREE.CylinderGeometry(0.24, 0.24, 0.1, 20), mat: mat(C.mint), shape: () => new CANNON.Cylinder(0.24, 0.24, 0.1, 12) },
  walk: { geo: new THREE.CylinderGeometry(0.27, 0.27, 0.09, 6), mat: mat('#8FB8FF'), shape: () => new CANNON.Cylinder(0.27, 0.27, 0.09, 6) },
  poop: { geo: new THREE.SphereGeometry(0.19, 14, 10), mat: mat('#3A443F'), shape: () => new CANNON.Sphere(0.19) },
  out: { geo: new THREE.BoxGeometry(0.34, 0.1, 0.34), mat: mat('#FF9F6B'), shape: () => new CANNON.Box(new CANNON.Vec3(0.17, 0.05, 0.17)) },
  train: { geo: new THREE.OctahedronGeometry(0.2), mat: mat('#C6A8FF'), shape: () => new CANNON.Sphere(0.17) },
  coin: { geo: new THREE.CylinderGeometry(0.22, 0.22, 0.07, 24), mat: mat(C.gold, { metalness: 0.5, roughness: 0.35 }), shape: () => new CANNON.Cylinder(0.22, 0.22, 0.07, 12) },
};
let tokens = [], spawnQueue = [], spawnClock = 0;
function spawnToken(type) {
  const d = TOKENS[type];
  const mesh = new THREE.Mesh(d.geo, d.mat); mesh.castShadow = true; scene.add(mesh);
  const b = new CANNON.Body({ mass: 0.3, shape: d.shape(), sleepSpeedLimit: 0.25, sleepTimeLimit: 0.4, angularDamping: 0.3, linearDamping: 0.05 });
  const r = BIN.half - 0.4;
  b.position.set(BIN.x + (Math.random() * 2 - 1) * r, BIN.h + 0.6 + Math.random() * 0.8, BIN.z + (Math.random() * 2 - 1) * r);
  b.quaternion.setFromEuler(Math.random() * 3, Math.random() * 3, Math.random() * 3);
  world.addBody(b); tokens.push({ mesh, b });
}
function clearTokens() { tokens.forEach(({ mesh, b }) => { scene.remove(mesh); world.removeBody(b); }); tokens = []; spawnQueue = []; }
function queueTokens(list) { // list: [[type, count]], interleaved so the pile is mixed
  const q = []; list.forEach(([type, n]) => { for (let i = 0; i < Math.round(n); i++) q.push(type); });
  for (let i = q.length - 1; i > 0; i--) { const j = Math.floor(Math.random() * (i + 1)); [q[i], q[j]] = [q[j], q[i]]; }
  spawnQueue = q;
}
const MAX_TOKENS = 130;
function unitFor(total) { let u = 1; for (const c of [1, 5, 10, 50, 100, 500, 1000, 5000]) { u = c; if (total / c <= MAX_TOKENS) break; } return u; }

/* ---------- Day playback ---------- */
let run = null; // {ev, i, phase, t, clock, counts}
const EVENT_SEC = { meal: 1.5, walk: 3.4, poop: 1.3, train: 1.6, out: 1.2 };
const clockStr = (m) => `${String(Math.floor(m / 60)).padStart(2, '0')}:${String(Math.floor(m % 60)).padStart(2, '0')}`;
function startDay() {
  const { ev } = buildDay(state.stage);
  clearTokens();
  run = { ev, i: -1, phase: 'gap', t: 0, clock: 390, from: 390, counts: { meal: 0, walk: 0, poop: 0, out: 0, train: 0, steps: 0, min: 0 } };
  nextEvent();
}
function nextEvent() {
  run.i++;
  if (run.i >= run.ev.length) { run.phase = 'end'; run.t = 0; run.from = run.clock; dogAnim.mode = 'idle'; moveDog(HOME, 0.5); caption(t('cap_end')); return; }
  run.phase = 'gap'; run.t = 0; run.from = run.clock;
}
let dogMove = null;
function moveDog(to, sec) { dogMove = { from: dog.position.clone(), to: to.clone(), t: 0, sec }; }
function beginEvent(e) {
  run.phase = 'event'; run.t = 0; caption(t('cap_' + e.type));
  if (e.type === 'meal') { moveDog(new THREE.Vector3(BOWL.x + 0.45, 0, BOWL.z - 0.35), 0.35); dogAnim.mode = 'eat'; kibble.visible = true; }
  if (e.type === 'walk') { dogAnim.mode = 'walk'; dogMove = null; }
  if (e.type === 'poop') { moveDog(new THREE.Vector3(2.9, 0, 1.2), 0.4); dogAnim.mode = 'squat'; }
  if (e.type === 'train') { moveDog(HOME, 0.3); dogAnim.mode = 'sit'; }
  if (e.type === 'out') { moveDog(DOOR, 0.45); dogAnim.mode = 'trot'; }
}
function endEvent(e) {
  const c = run.counts; c[e.type]++;
  if (e.type === 'walk') { c.steps += e.dur * STEPS_PER_MIN; c.min += e.dur; }
  if (e.type === 'train') c.min += e.dur;
  if (e.type === 'meal') kibble.visible = false;
  spawnToken(e.type); dogAnim.mode = 'idle';
  if (e.type !== 'walk') moveDog(HOME, 0.4); else moveDog(HOME, 0.5);
  renderHud(); nextEvent();
}
function tickDay(dt) {
  if (!run) return;
  run.t += dt;
  if (run.phase === 'gap') {
    const e = run.ev[run.i], k = Math.min(run.t / 0.55, 1);
    run.clock = run.from + (e.at - run.from) * k;
    if (k >= 1) beginEvent(e);
  } else if (run.phase === 'event') {
    const e = run.ev[run.i], sec = EVENT_SEC[e.type], k = Math.min(run.t / sec, 1);
    run.clock = e.at + e.dur * k;
    if (e.type === 'walk') {
      const a = -Math.PI / 2 + k * Math.PI * 2, R = 3.2, ease = Math.min(1, k * 8, (1 - k) * 8);
      const px = Math.cos(a) * R, pz = -Math.sin(a) * R;
      dog.position.set(HOME.x + (px - HOME.x) * ease, 0, HOME.z + (pz - HOME.z) * ease);
      dog.rotation.y = Math.atan2(-Math.sin(a), -Math.cos(a));
      $('#hud-steps').textContent = nf(run.counts.steps + e.dur * STEPS_PER_MIN * k);
    }
    if (k >= 1) endEvent(e);
  } else if (run.phase === 'end') {
    run.clock = run.from + (1290 - run.from) * Math.min(run.t / 0.9, 1);
    if (run.t > 1.6) { run = null; state.played = true; go('sum', true); }
  }
  if (run) $('#clock').textContent = clockStr(run.clock);
}
function animateDog(dt, time) {
  if (dogMove) {
    dogMove.t += dt; const k = Math.min(dogMove.t / dogMove.sec, 1), e = k * k * (3 - 2 * k);
    const d = dogMove.to.clone().sub(dogMove.from);
    dog.position.lerpVectors(dogMove.from, dogMove.to, e);
    if (d.lengthSq() > 0.01 && k < 1) dog.rotation.y = Math.atan2(d.x, d.z);
    if (k >= 1) { dogMove = null; if (dogAnim.mode === 'eat') dog.rotation.y = Math.atan2(BOWL.x - dog.position.x, BOWL.z - dog.position.z); if (dogAnim.mode === 'idle' || dogAnim.mode === 'sit') dog.rotation.y = 0.5; }
  }
  const moving = dogAnim.mode === 'walk' || dogAnim.mode === 'trot' || !!dogMove;
  dogAnim.gait += dt * (moving ? 16 : 0);
  const sw = moving ? Math.sin(dogAnim.gait) * 0.6 : 0;
  legs[0].rotation.x = sw; legs[3].rotation.x = sw; legs[1].rotation.x = -sw; legs[2].rotation.x = -sw;
  tail.rotation.y = Math.sin(time * (dogAnim.mode === 'idle' ? 6 : 12)) * 0.5;
  const target = { rx: 0, y: 0, hx: 0 };
  if (dogAnim.mode === 'eat') target.hx = 0.75 + Math.sin(time * 14) * 0.12;
  if (dogAnim.mode === 'sit') { target.rx = -0.5; target.y = -0.12; target.hx = -0.25; }
  if (dogAnim.mode === 'squat') { target.rx = -0.3; target.y = -0.14; }
  if (moving) target.y = Math.abs(Math.sin(dogAnim.gait)) * 0.05;
  const L = Math.min(1, dt * 12);
  dBody.rotation.x += (target.rx - dBody.rotation.x) * L;
  dBody.position.y += (target.y - dBody.position.y) * L;
  head.rotation.x += (target.hx - head.rotation.x) * L;
}

/* ---------- Bin label + info points (HTML anchored to 3D positions) ---------- */
const TOKEN_COLORS = { meal: C.mint, walk: '#8FB8FF', poop: '#3A443F', out: '#FF9F6B', train: '#C6A8FF' };
let binUnit = '';
function renderBinLabel() {
  const el = $('#binlabel'), coins = state.step === 'cost' || state.step === 'result';
  const kinds = ['meal', 'walk', 'poop', ...(state.stage === 'puppy' ? ['out'] : []), 'train'];
  el.innerHTML = `<strong>${t(coins ? 'bin_cost' : 'bin_jobs')}</strong>` + (binUnit ? `<span>${binUnit}</span>` : '') +
    (coins ? '' : `<ul>${kinds.map((k) => `<li><i style="background:${TOKEN_COLORS[k]}"></i>${t('tok_' + k)}</li>`).join('')}</ul>`);
}
const SPOTS = [
  { id: 'bowl', pos: new THREE.Vector3(-0.95, 0.75, 0.55) }, { id: 'path', pos: new THREE.Vector3(-3.1, 0.5, -0.6) },
  { id: 'door', pos: new THREE.Vector3(0, 0.5, 2.3) }, { id: 'bed', pos: new THREE.Vector3(1.0, 0.75, -0.9) },
  { id: 'life', pos: new THREE.Vector3(3.9, 2.0, -1.2) },
];
const spotLayer = $('#spots');
SPOTS.forEach((sp) => {
  const b = document.createElement('button'); b.className = 'spot'; b.type = 'button'; b.textContent = 'i'; b.dataset.spot = sp.id;
  spotLayer.appendChild(b); sp.el = b;
});
let openSpot = null;
function showInfo(id) {
  openSpot = id; const box = $('#info');
  document.querySelectorAll('.spot').forEach((b) => b.setAttribute('aria-expanded', b.dataset.spot === id));
  if (!id) { box.hidden = true; return; }
  box.hidden = false;
  $('#info-title').textContent = t('i_' + id + '_h'); $('#info-text').textContent = t('i_' + id + '_p'); $('#info-src').textContent = t('i_' + id + '_s');
}
const tmpV = new THREE.Vector3();
function place(el, v, dx = 0, dy = 0) {
  tmpV.copy(v).project(camera);
  const w = canvas.clientWidth, h = canvas.clientHeight;
  const x = (tmpV.x * 0.5 + 0.5) * w + dx, y = (-tmpV.y * 0.5 + 0.5) * h + dy;
  el.style.transform = `translate(${Math.round(x)}px, ${Math.round(y)}px)`;
  return [x, y];
}
function placeOverlays() {
  const w = canvas.clientWidth;
  SPOTS.forEach((sp) => {
    const [x, y] = place(sp.el, sp.pos, -14, -14);
    if (sp.id === openSpot) { const box = $('#info'), bw = box.offsetWidth; box.style.transform = w < 760 ? 'translate(8px, 60px)' : `translate(${Math.round(Math.min(Math.max(8, x - bw / 2 + 14), w - bw - 8))}px, ${Math.round(y + 38)}px)`; }
  });
  const lab = $('#binlabel'); tmpV.set(BIN.x, BIN.h + 0.25, BIN.z).project(camera);
  const lx = (tmpV.x * 0.5 + 0.5) * w, ly = (-tmpV.y * 0.5 + 0.5) * canvas.clientHeight;
  lab.style.transform = `translate(${Math.round(Math.min(Math.max(8, lx - lab.offsetWidth / 2), w - lab.offsetWidth - 8))}px, ${Math.round(Math.max(64, ly - lab.offsetHeight))}px)`;
}

/* ---------- UI ---------- */
function caption(text) { const el = $('#caption'); el.textContent = text; el.classList.remove('pop'); void el.offsetWidth; el.classList.add('pop'); }
function renderHud() {
  const c = run ? run.counts : null; if (!c) return;
  $('#hud-meals').textContent = c.meal; $('#hud-bags').textContent = c.poop; $('#hud-steps').textContent = nf(c.steps);
  $('#hud-min').textContent = c.min; $('#hud-outs').textContent = c.out;
}
const hours = (m) => (m >= 6000 ? `${nf(m / 60)} ${t('h')}` : m >= 60 ? `${nf(m / 60, 1)} ${t('h')}` : `${nf(m)} ${t('min')}`);
function renderSummary(keepPile = false) {
  const days = SCALES[state.scale], x = totals(state.stage, days), isLife = state.scale === 'life';
  const row = (label, value, tag) => `<li><span>${label}<em class="tag ${tag}">${t('tag_' + tag)}</em></span><b>${value}</b></li>`;
  let html = row(t('r_walk'), hours(x.walkMin), 'src') + row(t('r_steps'), nf(x.steps), 'rule') + row(t('r_meals'), nf(x.meals), 'src');
  if (x.takeOuts) html += row(t('r_outs'), nf(x.takeOuts), 'src');
  html += row(t('r_bags'), nf(x.poops), 'rule') + row(t('r_train'), hours(x.trainMin), 'src');
  if (state.scale !== 'day') html += row(t('r_groom'), nf(x.groom), 'src');
  $('#sum-list').innerHTML = html;
  $('#sum-note').textContent = isLife ? t(state.stage === 'puppy' ? 'note_life_puppy' : 'note_life') : state.stage === 'puppy' ? t('note_puppy') : '';
  const list = [['meal', x.meals], ['walk', x.walks], ['poop', x.poops], ['out', x.takeOuts], ['train', days]];
  const unit = unitFor(list.reduce((s, [, n]) => s + n, 0));
  if (!keepPile) { clearTokens(); queueTokens(list.map(([k, n]) => [k, Math.max(n ? 1 : 0, n / unit)])); }
  binUnit = unit > 1 ? t('legend').replace('{n}', nf(unit)) : t('legend1'); renderBinLabel();
  document.querySelectorAll('#scale button').forEach((b) => b.setAttribute('aria-pressed', b.dataset.scale === state.scale));
}
let costTimer = null;
function coinUnit(total) { for (const c of [10, 20, 50, 100, 200, 500, 1000, 2000]) if (total / c <= 150) return c; return 5000; }
function renderCost(respawn = true) {
  const year = state.monthly * 12, life = state.setup + year * LIFE_YEARS;
  $('#monthly-out').textContent = money(state.monthly); $('#setup-out').textContent = money(state.setup);
  $('#c-year').textContent = money(year); $('#c-life').textContent = money(life, 0, 10);
  $('#c-day').textContent = (state.cur === 'GBP' ? '' : '~') + fmtCur(conv(year / 365.25), 2);
  document.querySelectorAll('#cur button').forEach((b) => b.setAttribute('aria-pressed', b.dataset.cur === state.cur));
  $('#rate-note').textContent = state.cur === 'GBP' ? '' : t('rate_note').replace('{r}', `1 £ = ${nf(RATES[state.cur], 4)} ${SYMBOL[state.cur]}`);
  const unit = coinUnit(conv(life));
  binUnit = t('legend_coin').replace('{n}', fmtCur(unit)); renderBinLabel();
  if (!respawn) return;
  clearTimeout(costTimer);
  costTimer = setTimeout(() => { clearTokens(); queueTokens([['coin', Math.max(1, conv(life) / unit)]]); }, 220);
}
function promiseItems() {
  const d = totals(state.stage, 1), w = totals(state.stage, 7);
  return [
    t('p_walk').replace('{m}', nf(d.walkMin)), t('p_meals').replace('{n}', nf(d.meals)),
    ...(d.takeOuts ? [t('p_outs').replace('{n}', nf(d.takeOuts))] : []),
    t('p_bags'), t('p_week').replace('{h}', nf((w.walkMin + w.trainMin) / 60, 1)),
    t('p_cost').replace('{c}', money(state.monthly)),
  ];
}
function renderResult() { $('#promise').innerHTML = promiseItems().map((s) => `<li>${s}</li>`).join(''); }
/* Summary sheet (decision T8): built in the browser from the current choices, printed or saved as PDF by the
   parent. No files, no e-mail. On petprep.si the same builder takes the breed's numbers from registry.json. */
function renderSheet() {
  const cols = ['day', 'week', 'year', 'life'], X = cols.map((c) => totals(state.stage, SCALES[c]));
  const tag = (k) => `<em class="tag ${k}">${t('tag_' + k)}</em>`;
  const row = (label, k, f) => `<tr><td>${label}${tag(k)}</td>${X.map((x, i) => `<td>${f(x, i)}</td>`).join('')}</tr>`;
  const year = state.monthly * 12, life = state.setup + year * LIFE_YEARS;
  const note = [state.stage === 'puppy' ? t('note_life_puppy') : t('note_life'), state.stage === 'puppy' ? t('note_puppy') : ''].filter(Boolean).join(' ');
  $('#sheet-page').innerHTML = `
    <header><div class="brand">${document.querySelector('header .brand').innerHTML}</div><span class="kick">${t('sheet_k')}</span></header>
    <h1 id="sheet-h">${t('sheet_h')}</h1>
    <p class="lede">${t(state.stage === 'puppy' ? 'stage_puppy' : 'stage_adult')}. ${t('sheet_sub')}</p>
    <h2>${t('sheet_care')}</h2>
    <table><thead><tr><th></th>${cols.map((c) => `<th>${t('s_' + c)}</th>`).join('')}</tr></thead><tbody>
      ${row(t('r_walk'), 'src', (x) => hours(x.walkMin))}${row(t('r_steps'), 'rule', (x) => nf(x.steps))}${row(t('r_meals'), 'src', (x) => nf(x.meals))}
      ${X[0].takeOuts ? row(t('r_outs'), 'src', (x) => nf(x.takeOuts)) : ''}${row(t('r_bags'), 'rule', (x) => nf(x.poops))}
      ${row(t('r_train'), 'src', (x) => hours(x.trainMin))}${row(t('r_groom'), 'src', (x, i) => (i ? nf(x.groom) : '–'))}
    </tbody></table>
    <p class="small">${note}</p>
    <h2>${t('sheet_cost')}</h2>
    <div class="costs"><div><span>${t('monthly')}</span><b>${money(state.monthly)}</b></div><div><span>${t('setup')}</span><b>${money(state.setup)}</b></div>
      <div><span>${t('c_year')}</span><b>${money(year)}</b></div><div><span>${t('c_life')}</span><b>${money(life, 0, 10)}</b></div></div>
    <p class="small">${t('cost_note')} ${state.cur === 'GBP' ? '' : t('rate_note').replace('{r}', `1 £ = ${nf(RATES[state.cur], 4)} ${SYMBOL[state.cur]}`)}</p>
    <h2>${t('sheet_promise')}</h2>
    <p>${t('sheet_promise_p')}</p>
    <ul class="chk">${promiseItems().map((s) => `<li>${s}</li>`).join('')}</ul>
    <div class="sign"><div>${t('sign_child')}</div><div>${t('sign_parent')}</div><div>${t('sign_date')}</div></div>
    <p class="small"><b>${t('src_h')}.</b> ${t('src')}</p>
    <p class="small">${t('sheet_foot')}</p>`;
}
let sheetReturn = null;
function openSheet() {
  renderSheet(); sheetReturn = document.activeElement;
  $('#sheet').hidden = false; document.body.classList.add('sheet-open'); $('#sheet-print').focus();
}
function closeSheet() { $('#sheet').hidden = true; document.body.classList.remove('sheet-open'); sheetReturn?.focus(); }
$('#sheet-open').addEventListener('click', openSheet);
$('#sheet-close').addEventListener('click', closeSheet);
$('#sheet-print').addEventListener('click', () => window.print());
$('#sheet').addEventListener('click', (e) => { if (e.target.id === 'sheet') closeSheet(); });
addEventListener('keydown', (e) => { if (e.key === 'Escape' && !$('#sheet').hidden) closeSheet(); });
function applyStrings() {
  document.documentElement.lang = state.lang;
  document.querySelectorAll('[data-t]').forEach((el) => { el.innerHTML = t(el.dataset.t); });
  document.querySelectorAll('[data-t-ph]').forEach((el) => { el.placeholder = t(el.dataset.tPh); });
  document.querySelectorAll('#lang button').forEach((b) => b.setAttribute('aria-pressed', b.dataset.lang === state.lang));
  document.querySelectorAll('#stage button').forEach((b) => b.setAttribute('aria-pressed', b.dataset.stage === state.stage));
  document.title = t('title'); $('#sponsor-link').href = t('sponsor_url');
  if (state.step === 'sum') renderSummary();
  if (state.step === 'cost') renderCost(false);
  if (state.step === 'result') renderResult();
  if (state.step === 'day') binUnit = t('legend1');
  renderBinLabel(); showInfo(openSpot);
  if (!$('#sheet').hidden) renderSheet();
}
function go(step, keep = false) {
  state.step = step; document.body.dataset.step = step;
  document.querySelectorAll('[data-panel]').forEach((p) => { p.hidden = p.dataset.panel !== step; });
  if (step === 'intro' || step === 'day') binUnit = step === 'day' ? t('legend1') : '';
  showInfo(null); renderBinLabel();
  if (step === 'intro') { clearTokens(); run = null; dogAnim.mode = 'idle'; moveDog(HOME, 0.3); kibble.visible = false; }
  if (step === 'day') { $('#hud-outs-wrap').hidden = state.stage !== 'puppy'; startDay(); renderHud(); }
  if (step === 'sum') { const fromDay = state.scale === 'day' && tokens.length > 0 && keep; state.scale = 'day'; renderSummary(fromDay); }
  if (step === 'cost') renderCost();
  if (step === 'result') renderResult();
  const h = $(`[data-panel="${step}"] h1, [data-panel="${step}"] h2`); if (h) h.focus?.();
}
document.addEventListener('click', (e) => {
  const b = e.target.closest('button'); if (!b) { if (!e.target.closest('#info')) showInfo(null); return; }
  if (b.dataset.spot) { showInfo(openSpot === b.dataset.spot ? null : b.dataset.spot); return; }
  if (b.id === 'info-close') { showInfo(null); return; }
  if (b.dataset.go) go(b.dataset.go);
  if (b.dataset.lang) { state.lang = b.dataset.lang; applyStrings(); }
  if (b.dataset.cur) { state.cur = b.dataset.cur; renderCost(); }
  if (b.dataset.stage) { state.stage = b.dataset.stage; applyStrings(); }
  if (b.dataset.scale) { state.scale = b.dataset.scale; renderSummary(); }
  if (b.id === 'skip') { run = null; kibble.visible = false; dogAnim.mode = 'idle'; moveDog(HOME, 0.3); state.played = true; go('sum'); }
});
$('#monthly').addEventListener('input', (e) => { state.monthly = +e.target.value; renderCost(); });
$('#setup').addEventListener('input', (e) => { state.setup = +e.target.value; renderCost(); });

/* ---------- Camera, resize, loop ---------- */
let az = 0.35, azTarget = 0.35, drag = null, viewShift = [0, 0];
canvas.addEventListener('pointerdown', (e) => { drag = { x: e.clientX, az: azTarget }; canvas.setPointerCapture(e.pointerId); });
canvas.addEventListener('pointermove', (e) => { if (drag) azTarget = drag.az - (e.clientX - drag.x) * 0.006; });
canvas.addEventListener('pointerup', () => { drag = null; });
canvas.addEventListener('pointercancel', () => { drag = null; });
function resize() {
  const w = canvas.clientWidth, h = canvas.clientHeight;
  renderer.setSize(w, h, false); camera.aspect = w / h; viewShift = [-1, -1]; camera.clearViewOffset(); camera.updateProjectionMatrix();
}
new ResizeObserver(resize).observe(canvas); resize();
const clock = new THREE.Clock();
function frame() {
  const dt = Math.min(clock.getDelta(), 0.05), time = clock.elapsedTime;
  tickDay(dt); animateDog(dt, time);
  // token spawning + physics
  spawnClock += dt;
  while (spawnQueue.length && spawnClock > 0.03) { spawnClock -= 0.03; spawnToken(spawnQueue.pop()); }
  if (!spawnQueue.length) spawnClock = 0;
  world.step(1 / 60, dt, 3);
  for (const { mesh, b } of tokens) { mesh.position.copy(b.position); mesh.quaternion.copy(b.quaternion); }
  // sun follows the clock while the day plays
  const dayK = run ? THREE.MathUtils.clamp((run.clock - 360) / 960, 0, 1) : 0.35;
  const sa = Math.PI * (0.12 + dayK * 0.76);
  sun.position.set(Math.cos(sa) * -9 + 2, Math.sin(sa) * 7.5 + 0.5, -7);
  sunLight.position.set(Math.cos(sa) * -8 + 2, Math.sin(sa) * 9 + 3, 5);
  scene.background.lerpColors(SKY_DAY, SKY_EVE, Math.max(0, (dayK - 0.6) / 0.4));
  // camera: portrait screens stand further back and look at the middle of platform + bin
  const portrait = camera.aspect < 0.9;
  if (!drag && !reduceMotion) azTarget += Math.sin(time * 0.25) * 0.0006;
  az += (azTarget - az) * 0.12;
  const hf = Math.tan(THREE.MathUtils.degToRad(18)) * camera.aspect;
  const dist = Math.max(17.5, 8.3 / hf), cx = 2.4;
  camera.position.set(cx + Math.sin(az) * dist, dist * 0.36, Math.cos(az) * dist);
  camera.lookAt(cx, 1.1, 0);
  const w = canvas.clientWidth, h = canvas.clientHeight;
  const sx = !portrait && state.step !== 'day' ? Math.min(220, w * 0.16) : 0, sy = portrait && state.step === 'day' ? Math.round(h * 0.16) : 0;
  if (sx !== viewShift[0] || sy !== viewShift[1]) { viewShift = [sx, sy]; if (sx || sy) camera.setViewOffset(w, h, -sx, sy, w, h); else camera.clearViewOffset(); }
  renderer.render(scene, camera);
  placeOverlays();
  requestAnimationFrame(frame);
}
addEventListener('keydown', (e) => { if (e.key === 'Escape') showInfo(null); });
applyStrings(); go('intro'); frame();
window.__pp = { state, go, totals, get tokens() { return tokens.length; } };
