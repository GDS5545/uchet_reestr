/* ISMA · Elementor — декоративный слой. Вся структура/тексты/кнопки — нативные виджеты Elementor,
   здесь только то, что в настройках виджетов задать нельзя: орнаменты, градиент текста, кольца, анимации. */
:root{
  --abyss:#fbf8f1;--deep:#f3eee2;--teal:#0a7d85;--teal-lt:#17a6b0;--teal-dk:#08595f;
  --gold:#a8832f;--orn:#12806a;--gold-2:#c9a24f;--ivory:#0f3a3d;--mist:#5a7674;--line:rgba(15,58,61,.13);
  --foil:linear-gradient(100deg,#8f6b22 0%,#c9a24f 42%,#a8832f 68%,#7d5c1b 100%);
  --foil-btn:linear-gradient(100deg,#f3dfa6 0%,#dcbb6b 50%,#f0d894 100%);
  --btn:linear-gradient(100deg,#08777f,#17a6b0);
}
html{scroll-behavior:smooth;scroll-padding-top:84px}
body{background:var(--abyss);font-family:'Montserrat','Noto Sans','Segoe UI',Arial,sans-serif;overflow-x:hidden}

/* ---------- ключевые кадры ---------- */
@keyframes isma-drift{to{background-position:96px 96px}}
@keyframes isma-driftH{to{background-position:120px 0}}
@keyframes isma-driftP{to{background-position:100px 120px}}
@keyframes isma-driftB{to{background-position:70px center}}
@keyframes isma-breathe{0%,100%{opacity:1}50%{opacity:.5}}
@keyframes isma-spin{to{rotate:360deg}}
@keyframes isma-kb{from{transform:scale(1.1)}to{transform:scale(1)}}
@keyframes isma-bob{0%,100%{transform:translateY(0) scale(1)}50%{transform:translateY(-3px) scale(1.08)}}
@keyframes isma-draw{from{stroke-dashoffset:1}to{stroke-dashoffset:0}}
@keyframes isma-spin3d{0%{transform:rotateX(9deg) rotateY(-32deg) translateY(0)}100%{transform:rotateX(-5deg) rotateY(32deg) translateY(-12px)}}

/* ---------- золотая фольга для текста ---------- */
.isma-gold .elementor-heading-title,
.isma-gold .elementor-counter-number-wrapper,
.isma-gold .elementor-counter-number-wrapper .elementor-counter-number,
.isma-gold.elementor-widget-heading .elementor-heading-title span.isma-g,
span.isma-g{
  background:var(--foil);-webkit-background-clip:text;background-clip:text;-webkit-text-fill-color:transparent;color:transparent}
.isma-gold .elementor-counter-number-prefix,.isma-gold .elementor-counter-number-suffix{-webkit-text-fill-color:var(--gold);color:var(--gold)}
.isma-gold .elementor-counter-number-suffix{font-size:13px;font-weight:600;letter-spacing:.08em;margin-left:6px}
.isma-gold .elementor-counter-number-suffix{background:none}

/* ---------- eyebrow с линией ---------- */
.isma-eyebrow .elementor-heading-title{display:flex;align-items:center;gap:14px}
.isma-eyebrow .elementor-heading-title::before{content:"";width:34px;height:1px;background:var(--gold);flex:none}

/* ---------- шапка ---------- */
.isma-header{position:sticky!important;top:0;z-index:50;backdrop-filter:blur(14px);-webkit-backdrop-filter:blur(14px)}
.admin-bar .isma-header{top:32px}
.isma-nav a{text-decoration:none}
.isma-lang .elementor-button{padding:6px 12px!important;border-radius:99px}
.isma-lang.is-on .elementor-button{background:var(--teal)!important;color:#fff!important}

/* ---------- секции и орнаменты ---------- */
.isma-sec{position:relative}
.isma-sec>.e-con-inner{position:static}
.isma-sec>*:not(.isma-orn),.isma-sec>.e-con-inner>*:not(.isma-orn){position:relative;z-index:1}
.isma-orn{position:absolute!important;inset:0;width:auto!important;max-width:none!important;min-height:0!important;padding:0!important;margin:0!important;
  pointer-events:none;z-index:0!important;background-repeat:repeat!important;
  animation:isma-drift 80s linear infinite,isma-breathe 9s ease-in-out infinite}
.isma-orn.s{background-image:url("@@ORN_S@@")!important;background-size:96px 96px!important}
.isma-orn.h{background-image:url("@@ORN_H@@")!important;background-size:120px 80px!important;animation-name:isma-driftH,isma-breathe}
.isma-orn.p{background-image:url("@@ORN_P@@")!important;background-size:100px 120px!important;animation-name:isma-driftP,isma-breathe}
.isma-orn.tr{-webkit-mask-image:radial-gradient(ellipse 55% 80% at 100% 0%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);-webkit-mask-composite:source-in;mask-image:radial-gradient(ellipse 55% 80% at 100% 0%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);mask-composite:intersect}
.isma-orn.tl{-webkit-mask-image:radial-gradient(ellipse 55% 80% at 0% 0%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);-webkit-mask-composite:source-in;mask-image:radial-gradient(ellipse 55% 80% at 0% 0%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);mask-composite:intersect}
.isma-orn.br{-webkit-mask-image:radial-gradient(ellipse 55% 80% at 100% 100%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);-webkit-mask-composite:source-in;mask-image:radial-gradient(ellipse 55% 80% at 100% 100%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);mask-composite:intersect}
.isma-orn.bl{-webkit-mask-image:radial-gradient(ellipse 55% 80% at 0% 100%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);-webkit-mask-composite:source-in;mask-image:radial-gradient(ellipse 55% 80% at 0% 100%,#000 0,transparent 72%),linear-gradient(180deg,transparent 0,#000 24%,#000 76%,transparent 100%);mask-composite:intersect}

/* hero фон */
.isma-hero{background:radial-gradient(760px 520px at 85% 20%,rgba(23,166,176,.17),transparent 62%),var(--abyss)!important;overflow:hidden}

/* ---------- разделитель с орнаментом ---------- */
.isma-div .elementor-divider{padding-block:0}
.isma-div .elementor-divider-separator{align-items:center}
.isma-div .elementor-divider__element i,.isma-div .elementor-divider__element svg{display:none}
.isma-div .elementor-divider__element{width:15px;height:31px;margin:0 20px;background:url("@@GLYPH@@") center/contain no-repeat;animation:isma-bob 4.5s ease-in-out infinite}
.isma-div .elementor-divider-separator::before,.isma-div .elementor-divider-separator::after{border-color:rgba(18,128,106,.55)!important}
.isma-gly .elementor-icon i,.isma-gly .elementor-icon svg{display:none}
.isma-gly .elementor-icon{width:11px;height:23px;background:url("@@GLYPH@@") center/contain no-repeat;animation:isma-bob 4.5s ease-in-out infinite}

/* контурный орнамент у заголовка «Инвестиции» */
.isma-solo{position:absolute!important;right:8px;top:-14px;height:240px;width:116px!important;pointer-events:none;background:url("@@SOLO@@") center/contain no-repeat;opacity:.9}
@media(max-width:640px){.isma-solo{display:none}}

/* ---------- арка + рамки + кольца ---------- */
.isma-arch-wrap{position:relative;width:100%;max-width:470px;margin-inline:auto}
.isma-arch-wrap .isma-frame{position:absolute!important;inset:-14px -14px 14px 14px;width:auto!important;border:1px solid var(--gold);border-radius:999px 999px 30px 30px;opacity:.5;pointer-events:none}
.isma-arch-wrap .isma-frame.f2{inset:-28px -28px 28px 28px;border-radius:999px 999px 34px 34px;opacity:.18}
.isma-arch-wrap .isma-ring{position:absolute!important;left:50%;top:50%;width:168%!important;aspect-ratio:1;translate:-50% -50%;pointer-events:none;opacity:.9;
  background:url("@@RING1@@") center/contain no-repeat;animation:isma-spin 160s linear infinite;z-index:0}
.isma-arch-wrap .isma-ring.rev{width:128%!important;background-image:url("@@RING2@@");animation-duration:110s;animation-direction:reverse}
.isma-arch{position:relative;z-index:1;overflow:hidden;border-radius:999px 999px 28px 28px}
.isma-arch img{animation:isma-kb 2.6s cubic-bezier(.2,.7,.2,1) both}
.isma-arch::after{content:"";position:absolute;inset:0;background:linear-gradient(180deg,transparent 60%,rgba(15,58,61,.35));pointer-events:none}
.isma-badge{position:absolute!important;left:-18px;bottom:36px;z-index:3;width:auto!important;max-width:78%;backdrop-filter:blur(12px);-webkit-backdrop-filter:blur(12px)}
.isma-badge .dot{width:9px!important;height:9px;flex:none;border-radius:50%;box-shadow:0 0 0 5px rgba(91,227,160,.18)}
@media(max-width:640px){.isma-arch-wrap{max-width:380px}.isma-badge{left:8px}}

/* ---------- 3D-стекло ---------- */
.isma-glass{position:absolute!important;z-index:3;width:auto!important;aspect-ratio:.4824;perspective:1000px;pointer-events:none;filter:drop-shadow(0 26px 22px rgba(8,89,95,.35))}
.isma-glass.hero-gl{height:min(52vh,370px);right:-44px;top:-46px}
.isma-glass.form-gl{height:210px;right:-26px;top:-88px}
.isma-glass::before,.isma-glass::after{content:"";position:absolute;inset:0;background:center/contain no-repeat;transform-style:preserve-3d;animation:isma-spin3d 11s ease-in-out infinite alternate}
.isma-glass::before{background-image:url("@@GLASS@@");z-index:2}
.isma-glass::after{background-image:url("@@GLASSBACK@@");margin:6px 0 0 6px;filter:blur(1px)}
@media(max-width:1020px){.isma-glass.hero-gl{height:240px;right:-8px;top:-34px}}
@media(max-width:640px){.isma-glass.hero-gl{height:170px}.isma-glass.form-gl{height:150px;right:-6px;top:-66px}}

/* ---------- фото ---------- */
.isma-photo{overflow:hidden;position:relative;box-shadow:0 40px 70px -30px rgba(15,58,61,.4)}
.isma-aerial{position:sticky;top:96px;align-self:flex-start}
@media(max-width:1020px){.isma-aerial{position:static;order:-1}}

/* ---------- карточки ---------- */
.isma-card{transition:transform .3s,border-color .3s}
.isma-card:hover{transform:translateY(-4px);border-color:rgba(10,125,133,.45)!important}
.isma-card .isma-plan img{object-fit:contain}
.isma-pill .elementor-heading-title{background:none}

/* ---------- кнопки ---------- */
.isma-btn .elementor-button{transition:transform .2s,box-shadow .3s,background-position .6s;background-size:200% 100%}
.isma-btn.fill .elementor-button{box-shadow:0 12px 28px -12px rgba(10,125,133,.7)}
.isma-btn.fill .elementor-button:hover{background-position:100% 0;transform:translateY(-2px);box-shadow:0 18px 34px -12px rgba(10,125,133,.8)}
.isma-btn.ghost .elementor-button:hover{box-shadow:inset 0 0 0 1px var(--teal);color:var(--teal)}
.isma-fab{position:fixed!important;right:16px;bottom:16px;z-index:55;width:auto!important}
.isma-fab .elementor-button{box-shadow:0 14px 34px -8px rgba(37,211,102,.6)}
@media(max-width:640px){.isma-fab .elementor-button-text{display:none}.isma-fab .elementor-button{padding:14px}.isma-fab .elementor-button-icon{margin:0}}

/* ---------- форма ---------- */
.isma-form .elementor-field-group .elementor-field{transition:border-color .2s}
.isma-form .elementor-field-group .elementor-field:focus{outline:2px solid var(--gold);outline-offset:3px}

/* ---------- нижний ряд-лента ---------- */
.isma-band{height:84px;background:url("@@ORN_P@@") 0 center/70px 84px repeat-x;animation:isma-driftB 60s linear infinite;-webkit-mask-image:linear-gradient(90deg,transparent,#000 14%,#000 86%,transparent);mask-image:linear-gradient(90deg,transparent,#000 14%,#000 86%,transparent);pointer-events:none}

@media (prefers-reduced-motion:reduce){.isma-orn,.isma-band{opacity:1}*{animation:none!important;transition:none!important;scroll-behavior:auto!important}}

/* ---------- сброс отступов Elementor внутри страницы ---------- */
.isma-sec p,.isma-sec .elementor-heading-title{margin:0}
.isma-sec .elementor-widget:not(:last-child){margin-block-end:0}
.isma-sec .elementor-widget-text-editor p{margin:0}
.elementor-widget-image img{display:block}

/* ---------- появление ---------- */
@keyframes isma-rise{from{opacity:0;transform:translateY(18px)}to{opacity:1;transform:none}}
.isma-rise{animation:isma-rise .9s cubic-bezier(.2,.7,.2,1) both}
.isma-rise.d100{animation-delay:.1s}.isma-rise.d150{animation-delay:.15s}.isma-rise.d250{animation-delay:.25s}
.isma-rise.d400{animation-delay:.4s}.isma-rise.d550{animation-delay:.55s}
.isma-rv{transition:opacity .9s ease,transform .9s cubic-bezier(.2,.7,.2,1)}
.isma-rv.pre{opacity:0;transform:translateY(28px)}

/* ---------- числа ---------- */
.isma-count .u{font-size:13px;font-weight:600;letter-spacing:.08em;margin-left:6px;-webkit-text-fill-color:var(--gold);color:var(--gold);background:none}
.isma-count .elementor-heading-title{font-variant-numeric:tabular-nums;white-space:nowrap}

/* ---------- чипы фильтра ---------- */
.isma-chip .elementor-button{transition:.2s}
.isma-chip .elementor-button:hover{border-color:var(--teal)!important;color:var(--teal)!important}
.isma-chip.is-on .elementor-button{background:var(--teal)!important;color:#fff!important;border-color:transparent!important}

/* ---------- формы-подложки калькулятора и сортировки: без кнопки отправки ---------- */
.isma-calcform .elementor-field-type-submit,.isma-sortform .elementor-field-type-submit{display:none}
.isma-calcform .elementor-field-type-checkbox label{display:flex;align-items:center;gap:14px;cursor:pointer;font-size:14px;color:var(--ivory);text-transform:none;letter-spacing:0;font-weight:400}
.isma-calcform input[type=checkbox]{appearance:none;-webkit-appearance:none;width:48px;height:28px;border-radius:99px;background:rgba(15,58,61,.2);position:relative;cursor:pointer;flex:none;transition:.25s;margin:0;border:0}
.isma-calcform input[type=checkbox]::after{content:"";position:absolute;top:3px;left:3px;width:22px;height:22px;border-radius:50%;background:#fff;transition:.25s}
.isma-calcform input[type=checkbox]:checked{background:var(--teal)}
.isma-calcform input[type=checkbox]:checked::after{transform:translateX(20px)}
.isma-calcform .elementor-field-subgroup{display:block}
.isma-calcform .elementor-form-fields-wrapper,.isma-sortform .elementor-form-fields-wrapper{gap:22px 0}
@media(max-width:640px){.isma-sortform{width:100%!important}}

/* ---------- сообщение под формой заявки (WhatsApp) ---------- */
.isma-wa-msg:empty{display:none}
.isma-wa-msg.err{color:#c0392b;font-size:13px}
.isma-wa-msg.ok{display:grid;gap:14px;padding:22px;border-radius:18px;background:rgba(46,170,110,.08);border:1px solid rgba(46,170,110,.35);font-weight:700;color:var(--ivory)}
.isma-wa-msg.ok a{justify-self:start;display:inline-flex;padding:16px 26px;border-radius:99px;background:linear-gradient(100deg,#08777f,#17a6b0);color:#fff;text-decoration:none;font-size:13px;letter-spacing:.12em;text-transform:uppercase}
.isma-leadform .elementor-field-type-submit .elementor-button{cursor:pointer}
