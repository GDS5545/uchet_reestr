#!/usr/bin/env python3
"""Генератор Elementor-шаблона ISMA (RU/KZ) из нативных контейнеров и виджетов.

    python3 build_template.py --base https://SITE/wp-content/uploads/isma/

Создаёт isma-ru.json, isma-kz.json и isma-extra.css рядом со скриптом.
"""
import argparse, json, os, random, re, urllib.parse

HERE = os.path.dirname(os.path.abspath(__file__))
ap = argparse.ArgumentParser()
ap.add_argument("--base", default="https://example.com/wp-content/uploads/isma/",
                help="URL папки, куда загружены файлы из images/ (со слэшем на конце)")
ap.add_argument("--wa", default="77718882020")
ap.add_argument("--no-pro", action="store_true", help="без Pro-виджетов (форма заменяется кнопками)")
args = ap.parse_args()
BASE = args.base if args.base.endswith("/") else args.base + "/"
WA = args.wa

# ───────────────────────── палитра ─────────────────────────
ABYSS, DEEP, TEAL, TEAL_LT, TEAL_DK = "#fbf8f1", "#f3eee2", "#0a7d85", "#17a6b0", "#08595f"
GOLD, IVORY, MIST = "#a8832f", "#0f3a3d", "#5a7674"
LINE = "rgba(15,58,61,.13)"
FONT = "Montserrat"

# ───────────────────────── хелперы ─────────────────────────
_rnd = random.Random(5545)
def uid():
    return "%07x" % _rnd.getrandbits(28)

def sz(n, unit="px"):
    return {"unit": unit, "size": n, "sizes": []}

def dim(t, r=None, b=None, l=None, unit="px"):
    if r is None: r = b = l = t
    elif b is None: b, l = t, r
    return {"unit": unit, "top": str(t), "right": str(r), "bottom": str(b), "left": str(l),
            "isLinked": t == r == b == l}

def gap(n):
    return {"column": str(n), "row": str(n), "isLinked": True, "unit": "px"}

def typo(p, size=None, weight=None, ls=None, lh=None, tr=None, t=None, m=None, fam=FONT):
    d = {f"{p}_typography": "custom", f"{p}_font_family": fam}
    if size is not None:
        d[f"{p}_font_size"] = sz(size)
        if t: d[f"{p}_font_size_tablet"] = sz(t)
        if m: d[f"{p}_font_size_mobile"] = sz(m)
    if weight: d[f"{p}_font_weight"] = str(weight)
    if ls is not None: d[f"{p}_letter_spacing"] = sz(ls)
    if lh is not None: d[f"{p}_line_height"] = sz(lh, "em")
    if tr: d[f"{p}_text_transform"] = tr
    return d

def resp(key, desk, tab=None, mob=None):
    d = {key: desk}
    if tab is not None: d[key + "_tablet"] = tab
    if mob is not None: d[key + "_mobile"] = mob
    return d

def border(color=LINE, w=(1, 1, 1, 1), radius=None, prefix="border"):
    d = {f"{prefix}_border": "solid", f"{prefix}_width": dim(*w), f"{prefix}_color": color}
    if radius is not None:
        d[f"{prefix}_radius"] = radius if isinstance(radius, dict) else dim(radius)
    return d

def shadow(x, y, blur, spread, color, key="box_shadow"):
    return {f"{key}_box_shadow_type": "yes",
            f"{key}_box_shadow": {"horizontal": x, "vertical": y, "blur": blur, "spread": spread, "color": color}}

def anim(name="fadeInUp", delay=0, widget=True, dur="slow"):
    """Маркер анимации появления; превращается в CSS-класс на пост-обработке (поведение — в extra.js)."""
    return {"_rv": [name, delay]}

def link(url, ext=False):
    return {"url": url, "is_external": "on" if ext else "", "nofollow": "", "custom_attributes": ""}

def merge(*ds):
    o = {}
    for d in ds:
        o.update(d)
    return o

def W(wtype, settings, **extra):
    return {"id": uid(), "elType": "widget", "widgetType": wtype, "settings": settings, "elements": [], **extra}

def C(children=None, **s):
    return {"id": uid(), "elType": "container", "settings": s, "elements": children or [], "isInner": False}

def inner(children=None, **s):
    c = C(children, **s); c["isInner"] = True; return c

# ───────────────────────── виджеты ─────────────────────────
def heading(text, size=16, weight=400, color=IVORY, tag="div", cls="", align="left", ls=None, lh=None,
            tr=None, t=None, m=None, extra=None, link_=None):
    s = merge({"title": text, "header_size": tag, "align": align, "title_color": color,
               "_css_classes": cls}, typo("typography", size, weight, ls, lh, tr, t, m), extra or {})
    if link_: s["link"] = link(link_)
    return W("heading", s)

def text(html, size=16, color=MIST, weight=400, lh=1.6, cls="", extra=None, t=None, m=None):
    return W("text-editor", merge({"editor": html, "text_color": color, "_css_classes": cls},
                                  typo("typography", size, weight, None, lh, None, t, m), extra or {}))

def button(label, url, kind="fill", sm=False, icon=None, cls="", extra=None, blank=False):
    pad = dim(12, 18) if sm else dim(18, 28)
    fs = 11 if sm else 13
    s = merge({"text": label, "link": link(url, blank), "size": "md", "_css_classes": f"isma-btn {kind} {cls}".strip(),
               "border_radius": dim(99), "text_padding": pad},
              typo("typography", fs, 700, 1.4, 1, "uppercase"))
    if kind == "fill":
        s.update({"button_text_color": "#ffffff", "background_background": "gradient",
                  "background_color": "#08777f", "background_color_b": TEAL_LT, "background_gradient_angle": sz(100, "deg"),
                  "hover_color": "#ffffff"})
    elif kind == "ghost":
        s.update({"button_text_color": IVORY, "background_background": "classic", "background_color": "rgba(0,0,0,0)",
                  "hover_color": TEAL, "button_background_hover_color": "rgba(0,0,0,0)"})
        s.update(border(LINE, (1, 1, 1, 1), 99))
        s["button_hover_border_color"] = TEAL
    if icon:
        s["selected_icon"] = {"value": icon, "library": "fa-brands" if icon.startswith("fab") else "fa-solid"}
        s["icon_align"] = "left"; s["icon_indent"] = sz(10)
    s.update(extra or {})
    return W("button", s)

def divider_orn(cls="isma-div"):
    return W("divider", {"style": "solid", "weight": sz(1), "color": "rgba(18,128,106,.55)",
                         "look": "line_icon", "icon": {"value": "fas fa-star", "library": "fa-solid"},
                         "icon_size": sz(14), "icon_color": "#238696", "icon_view": "default",
                         "_css_classes": cls, "_margin": dim(0, 0, 56, 0), "_margin_mobile": dim(0, 0, 36, 0),
                         **anim("fadeIn", 0)})

# ───────────────────────── секции ─────────────────────────
def section(children, *, bg=ABYSS, cls="", sid=None, pad=(120, 80, 64), orn=(), extra=None):
    kids = [C(None, _css_classes=f"isma-orn {o}", content_width="full") for o in orn] + children
    s = merge({"content_width": "boxed", "boxed_width": sz(1232), "flex_direction": "column",
               "flex_gap": gap(0), "background_background": "classic", "background_color": bg,
               "padding": dim(pad[0], 16), "padding_tablet": dim(pad[1], 16), "padding_mobile": dim(pad[2], 16),
               "_css_classes": f"isma-sec {cls}".strip(), "overflow": "hidden" if "isma-hero" in cls else ""},
              {"_element_id": sid} if sid else {}, extra or {})
    s = {k: v for k, v in s.items() if v != ""}
    c = C(kids, **s)
    return c

def row2(left, right, wl=50, wr=50, g=72, align="center", cls="", stack_t=True):
    """Два столбца: на планшете — в колонку."""
    kw = dict(content_width="full", flex_direction="row", flex_gap=gap(g), flex_align_items=align,
              flex_direction_tablet="column", flex_direction_mobile="column", flex_wrap="nowrap",
              flex_gap_tablet=gap(32), width=sz(100, "%"), _css_classes=cls)
    L = C(left, content_width="full", flex_direction="column", flex_gap=gap(0), width=sz(wl, "%"),
          width_tablet=sz(100, "%"), width_mobile=sz(100, "%"))
    R = C(right, content_width="full", flex_direction="column", flex_gap=gap(0), width=sz(wr, "%"),
          width_tablet=sz(100, "%"), width_mobile=sz(100, "%"), flex_align_items="center")
    return C([L, R], **kw)

def grid(children, cols=3, tab=2, mob=1, g=20, **s):
    base = dict(container_type="grid", content_width="full",
                grid_columns_grid=sz(cols, "fr"), grid_columns_grid_tablet=sz(tab, "fr"), grid_columns_grid_mobile=sz(mob, "fr"),
                grid_gaps=gap(g))
    base.update(s)
    return C(children, **base)

# ───────────────────────── данные ─────────────────────────
STREETS = {"raim": "Райымбек батыр", "ash": "Б. Ашимова", "kald": "Ш. Калдаякова", "nur": "С. Нурмагамбетова"}
UNITS = [
    (1, 93.9, 1545000, ["raim"], 1, 1), (2, 194.7, 1596500, ["raim"], 1, 1), (3, 83.3, 1545000, ["raim"], 1, 2),
    (4, 302.3, 1596500, ["raim"], 1, 2), (5, 123.6, 1720100, ["ash"], 1, 3), (6, 107.3, 1720100, ["ash"], 1, 3),
    (7, 143.6, 1720100, ["ash"], 1, 4), (8, 148.8, 1854000, ["kald", "ash"], 1, 4), (13, 143.4, 1854000, ["kald"], 1, 5),
    (14, 191.3, 1854000, ["kald"], 1, 6), (15, 144.4, 1854000, ["kald"], 1, 6), (17, 336.6, 1200000, ["kald"], 2, 0),
    (21, 143.8, 1596500, ["nur"], 1, 7), (23, 127.3, 1596500, ["nur"], 1, 8), (26, 91.1, 1545000, ["raim"], 1, 9),
    (27, 170.7, 1545000, ["raim"], 1, 10), (28, 120.5, 1545000, ["raim"], 1, 10)]
UNITS = [dict(n=n, area=a, ppm=p, st=s, fl=f, bl=b, total=round(a * p)) for n, a, p, s, f, b in UNITS]
YIELD, DISC, DOWN, MONTHS = 0.0993, 0.10, 0.5, 6

fmt = lambda n: f"{round(n):,}".replace(",", " ")
dec = lambda n, d=1: f"{n:.{d}f}".replace(".", ",")

KZ = json.load(open(os.path.join(HERE, "src/kz.json"), encoding="utf8"))

L = {
 "ru": dict(
  ent="вход со стороны", fl=lambda f, b: "2 этаж" if f == 2 else f"{f} этаж, {b} блок", m2="м²", np="НП-",
  st={"raim": "ул. Райымбек батыр", "ash": "ул. Б. Ашимова", "kald": "ул. Ш. Калдаякова", "nur": "ул. С. Нурмагамбетова"},
  ask="Узнать о помещении", calc="Расчёт",
  o=["Цена по прайсу", "Цена со скидкой 10%", "Первоначальный взнос 50%", "Платёж в месяц, рассрочка 6 мес.",
     "Прогнозный доход в год", "Доходность", "Окупаемость"], oN="Итоговая цена", mln="млн", yrs="года",
  fine="Расчёт ориентировочный: доход принят как 9,93% от цены по прайсу, как в презентации. Не является гарантией дохода.",
  example="Пример расчёта для помещения", pick="Подобрать помещение",
  wa=lambda u: f"Здравствуйте! Интересует: {u}. Хочу получить планировки и актуальные цены на коммерческие помещения ISMA.",
  feat=["Потолки до 6 м (можно построить 2-й уровень антресоль)", "Отдельный вход", "Витражные окна",
        "Отдельная автономная вентиляционная шахта (идеально для общепита)", "Мокрые точки",
        "Большой уличный паркинг с трёх сторон ЖК", "Первая линия и близость к LRT станции «Театр»", "Аренда ≈9 000 ₸/м²"],
  dem=["504 квартиры бизнес-класса", "Сформированная коммерческая среда: Депутатский городок, школа, музей и мечеть Хазрет Султан",
       "Большой уличный паркинг", "Объект уже сдан"],
  alt_hero="ЖК ISMA, фасад в вечерней подсветке", alt_int="Интерьер коммерческого помещения с антресолью", alt_air="ЖК ISMA, вид сверху",
  form=dict(name="Имя", phone="Телефон", unit="Интересует", send="Отправить заявку", ok="Заявка отправлена. Менеджер свяжется с вами."),
  menu=["Преимущества", "Доходность", "Планировки", "Калькулятор", "Контакты"],
  all="Все", f2="2 этаж", chip={"raim": "Райымбек батыр", "ash": "Б. Ашимова", "kald": "Ш. Калдаякова", "nur": "С. Нурмагамбетова"},
  sort=["По номеру", "Сначала дешевле", "Сначала дороже", "По площади"], copy="Скопировать номер", copied="Скопировано"),
 "kz": dict(
  ent="кіреберіс:", fl=lambda f, b: "2-қабат" if f == 2 else f"{f}-қабат, {b}-блок", m2="м²", np="НП-",
  st={"raim": "Райымбек батыр көшесі", "ash": "Б. Ашимов көшесі", "kald": "Ш. Қалдаяқов көшесі", "nur": "С. Нұрмағамбетов көшесі"},
  ask="Үй-жай туралы білу", calc="Есептеу",
  o=["Прайс бойынша баға", "10% жеңілдікпен баға", "Алғашқы жарна 50%", "Айлық төлем, 6 айға бөліп төлеу",
     "Болжамды жылдық табыс", "Табыстылық", "Өтелу мерзімі"], oN="Түпкілікті баға", mln="млн", yrs="жыл",
  fine="Есеп шамамен жасалған: табыс презентациядағыдай прайс бағасының 9,93%-ы деп алынған. Табысқа кепілдік емес.",
  example="Есептеу мысалы, үй-жай", pick="Үй-жайды таңдап беріңіздер",
  wa=lambda u: f"Сәлеметсіз бе! Қызықтыратыны: {u}. ISMA коммерциялық үй-жайларының жоспарлары мен өзекті бағасын алғым келеді.",
  feat=["Төбе биіктігі 6 м-ге дейін (2-деңгейлі антресоль салуға болады)", "Бөлек кіреберіс", "Витраждық терезелер",
        "Бөлек автономды желдету шахтасы (қоғамдық тамақтану үшін өте қолайлы)", "Су нүктелері",
        "ТК-нің үш жағынан үлкен ашық тұрақ", "Бірінші линия және «Театр» LRT бекетіне жақындық", "Жалға беру ≈9 000 ₸/м²"],
  dem=["504 бизнес-класс пәтері", "Қалыптасқан коммерциялық орта: Депутаттар қалашығы, мектеп, мұражай және Хазірет Сұлтан мешіті",
       "Үлкен ашық тұрақ", "Объект пайдалануға берілген"],
  alt_hero="ISMA ТК, кешкі жарықтандырудағы қасбет", alt_int="Антресолі бар коммерциялық үй-жай интерьері", alt_air="ISMA ТК, жоғарыдан көрінісі",
  form=dict(name="Аты-жөніңіз", phone="Телефон", unit="Қызықтыратыны", send="Өтінімді жіберу", ok="Өтінім жіберілді. Менеджер сізбен хабарласады."),
  menu=["Артықшылықтар", "Табыстылық", "Жоспарлар", "Калькулятор", "Байланыс"],
  all="Барлығы", f2="2-қабат", chip={"raim": "Райымбек батыр", "ash": "Б. Ашимов", "kald": "Ш. Қалдаяқов", "nur": "С. Нұрмағамбетов"},
  sort=["Нөмірі бойынша", "Алдымен арзан", "Алдымен қымбат", "Ауданы бойынша"], copy="Нөмірді көшіру", copied="Көшірілді"),
}

# RU-тексты (ключ → значение), как в оригинале
RU = {
 'nav.cta': 'Заявка', 'hero.eyebrow': 'ЖК бизнес-класса ISMA · Астана',
 'hero.h1': 'Коммерческие помещения в ЖК бизнес-класса <em class="gold">ISMA</em>',
 'hero.sub': 'Готовый коммерческий актив на первой линии рядом с LRT «Театр». Объект сдан, потолки до 6 м, отдельные входы, парковка с трёх сторон ЖК.',
 'hero.cta1': 'Получить планировки и цены', 'hero.cta2': 'Написать в WhatsApp',
 'hero.f1v': 'до 6 м', 'hero.f1l': 'высота потолков', 'hero.f2v': 'от 1,4 млн ₸', 'hero.f2l': 'за квадратный метр',
 'hero.f3v': '≈ 10,1 года', 'hero.f3l': 'окупаемость', 'hero.badge': 'Объект сдан',
 'inv.eyebrow': 'Инвестиции', 'inv.h2': 'Готовый коммерческий актив в ЖК бизнес-класса',
 'inv.t1': 'пример стоимости помещения', 'inv.t2': 'прогнозный доход в год', 'inv.t3': 'валовая доходность в год',
 'inv.t4': 'ориентировочная окупаемость', 'inv.yr': 'года', 'inv.dh': 'При максимальной скидке 10%',
 'inv.d2': 'доходность ≈11,03%', 'inv.d3': 'окупаемость ≈9,1 года', 'inv.note': 'Показатели ориентировочные и не являются гарантией дохода.',
 'adv.eyebrow': 'Преимущества', 'adv.h2': 'Готово для бизнеса, потому что:',
 'dem.eyebrow': 'Локация', 'dem.h2': 'Почему здесь будет спрос?',
 'terms.h': 'Условия покупки', 'terms.1v': 'от 1,4 млн ₸', 'terms.1l': 'за квадратный метр', 'terms.2v': 'до 10%', 'terms.2l': 'скидка',
 'terms.3v': 'от 50%', 'terms.3l': 'первоначальный взнос', 'terms.4v': 'до 6 месяцев', 'terms.4l': 'рассрочка',
 'plans.eyebrow': 'Помещения', 'plans.h2': 'Планировки под разные бизнес-модели',
 'plans.sub': '17 помещений на первом и втором этажах. Выберите улицу входа или отсортируйте по цене и площади.',
 'calc.eyebrow': 'Расчёт', 'calc.h2': 'Калькулятор доходности', 'calc.unit': 'Помещение', 'calc.disc': 'Максимальная скидка 10%',
 'calc.hint': 'Первоначальный взнос от 50%, остаток в рассрочку до 6 месяцев.',
 'ct.eyebrow': 'Контакты', 'ct.h2': 'Обсудим ваше помещение', 'ct.sub': 'Менеджер подберёт помещение, пришлёт планировки и актуальные цены.',
 'ct.phone': 'Телефон и WhatsApp', 'ct.call': 'Позвонить', 'ct.addr': 'Офис и объект', 'ct.addrv': 'г. Астана, ул. Ш. Калдаякова 16',
 'ct.map': 'Открыть на карте', 'ct.site': 'Сайт', 'f.h': 'Оставьте заявку',
 'f.fine': 'Нажимая кнопку, вы соглашаетесь на обработку персональных данных.',
 'ft.l': '© ISMA Invest. Коммерческая недвижимость в Астане.', 'ft.r': 'Цены и показатели ориентировочные, не являются публичной офертой.',
 'fab': 'WhatsApp'}

WAF = {
 "ru": dict(msg="Здравствуйте! Меня зовут {n}. Телефон: {p}. Интересует: {u}. Хочу получить планировки и актуальные цены на коммерческие помещения ISMA.",
            errName="Укажите имя.", errPhone="Введите телефон полностью, например +7 771 888 20 20.",
            done="Заявка готова. Если WhatsApp не открылся, нажмите кнопку ниже.", btn="Отправить в WhatsApp"),
 "kz": dict(msg="Сәлеметсіз бе! Атым {n}. Телефон: {p}. Қызықтыратыны: {u}. ISMA коммерциялық үй-жайларының жоспарлары мен өзекті бағасын алғым келеді.",
            errName="Атыңызды жазыңыз.", errPhone="Телефонды толық енгізіңіз, мысалы +7 771 888 20 20.",
            done="Өтінім дайын. WhatsApp ашылмаса, төмендегі түймені басыңыз.", btn="WhatsApp-қа жіберу"),
}

# ───────────────────────── CSS ─────────────────────────
def build_css():
    tpl = open(os.path.join(HERE, "src/extra.css.tpl"), encoding="utf8").read()
    path = open(os.path.join(HERE, "src/ornament.path")).read().strip()
    q = lambda s: "data:image/svg+xml," + urllib.parse.quote(s, safe="/:=;,'()._- ")
    q2 = lambda s: "data:image/svg+xml," + urllib.parse.quote(s, safe="")
    orn_s = ("<svg xmlns='http://www.w3.org/2000/svg' width='96' height='96' viewBox='0 0 96 96'><g fill='none' stroke='#12806a' stroke-opacity='.3' stroke-width='1'>"
             "<rect x='24' y='24' width='48' height='48'/><rect x='24' y='24' width='48' height='48' transform='rotate(45 48 48)'/>"
             "<circle cx='48' cy='48' r='7'/><circle cx='48' cy='48' r='2' fill='#12806a' fill-opacity='.3'/></g></svg>")
    orn_h = ("<svg xmlns='http://www.w3.org/2000/svg' width='120' height='80' viewBox='0 0 120 80'><g fill='none' stroke='#12806a' stroke-opacity='.34' stroke-width='1.2' stroke-linecap='round' stroke-linejoin='round'>"
             "<path d='M60 64C60 46 70 35 82 37C95 39 97 55 85 57C75 58.5 70 50 77 47C82 45 86 50 82 53'/>"
             "<path d='M60 64C60 46 50 35 38 37C25 39 23 55 35 57C45 58.5 50 50 43 47C38 45 34 50 38 53'/><path d='M60 64V76'/>"
             "<path d='M60 14l6 6-6 6-6-6z'/><path d='M60 29v6'/><path d='M0 40l5 5-5 5M120 40l-5 5 5 5M0 40l-5 5 5 5M120 40l5 5-5 5'/></g></svg>")
    orn_p = ("<svg xmlns='http://www.w3.org/2000/svg' width='100' height='120' viewBox='0 0 100 120'><g fill='#238696' fill-opacity='.17'>"
             f"<path transform='translate(6 6) scale(0.00045)' d='{path}'/>"
             f"<path transform='translate(94 114) rotate(180) scale(0.00045)' d='{path}'/></g></svg>")
    glyph = f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 86512 179342'><path fill='#238696' d='{path}'/></svg>"
    solo = (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 86512 179342'><path fill='rgba(35,134,150,.08)' stroke='#238696' stroke-width='380' stroke-linejoin='round' d='{path}'/></svg>")
    ring1 = ("<svg xmlns='http://www.w3.org/2000/svg' viewBox='-300 -300 600 600'><g fill='none' stroke='#12806a' stroke-width='.8'><circle r='292' stroke-opacity='.25'/>"
             "<circle r='262' stroke-opacity='.3' stroke-dasharray='1.5 9'/><rect x='-170' y='-170' width='340' height='340' stroke-opacity='.28'/>"
             "<rect x='-170' y='-170' width='340' height='340' transform='rotate(45)' stroke-opacity='.28'/></g></svg>")
    ring2 = ("<svg xmlns='http://www.w3.org/2000/svg' viewBox='-300 -300 600 600'><g fill='none' stroke='#12806a' stroke-width='.8'><circle r='292' stroke-opacity='.2'/>"
             "<rect x='-205' y='-205' width='410' height='410' stroke-opacity='.22'/><rect x='-205' y='-205' width='410' height='410' transform='rotate(45)' stroke-opacity='.22'/></g></svg>")
    glass = (f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 86512 179342'><defs><linearGradient id='g' x1='0' y1='0' x2='1' y2='1'>"
             "<stop offset='0' stop-color='#d8fbf8' stop-opacity='.95'/><stop offset='.38' stop-color='#58cdd3' stop-opacity='.88'/><stop offset='1' stop-color='#0c7f8e' stop-opacity='.92'/></linearGradient></defs>"
             f"<path fill='url(#g)' stroke='#fff' stroke-opacity='.8' stroke-width='330' d='{path}'/></svg>")
    glassb = f"<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 86512 179342'><path fill='#04343e' fill-opacity='.9' d='{path}'/></svg>"
    rep = {"ORN_S": orn_s, "ORN_H": orn_h, "ORN_P": orn_p, "GLYPH": glyph, "SOLO": solo, "RING1": ring1, "RING2": ring2,
           "GLASS": glass, "GLASSBACK": glassb}
    for k, v in rep.items():
        tpl = tpl.replace(f"@@{k}@@", q2(v))
    return tpl

CSS = build_css()


def mini_form(cls, fields, show_labels=False, button=None, extra=None):
    """Form (Pro): используется и для заявки, и как «поля ввода» калькулятора/сортировки (кнопка скрыта CSS)."""
    return W("form", merge({
        "form_name": "ISMA", "form_fields": fields, "show_labels": "true" if show_labels else "", "mark_required": "",
        "button_text": button or "OK", "button_width": "100", "column_gap": sz(18), "row_gap": sz(18), "_css_classes": cls,
        "label_color": MIST, "field_text_color": IVORY, "field_background_color": "#ffffff", "field_border_color": LINE,
        "field_border_width": dim(1), "field_border_radius": dim(14),
        "button_text_color": "#ffffff", "button_background_color": "#08777f", "button_border_radius": dim(99),
        "button_text_padding": dim(18, 28), "button_hover_background_color": TEAL_LT, "success_message_color": "#1f8a58",
        "error_message_color": "#c0392b"}, typo("label_typography", 11, 700, 1.76, None, "uppercase"),
        typo("field_typography", 15, 500), typo("button_typography", 13, 700, 1.56, 1, "uppercase"), extra or {}))

# ───────────────────────── страница ─────────────────────────
def build(lang):
    t = L[lang]
    T = (lambda k: KZ[k]) if lang == "kz" else (lambda k: RU[k])
    def TK(k):  # текст с учётом KZ, <em class="gold"> → золотой span
        s = T(k)
        return s.replace('<em class="gold">', f'<span class="isma-g" style="color:{GOLD};font-weight:600">').replace("</em>", "</span>")
    img = lambda f: {"url": BASE + f, "id": ""}
    wa_link = f"https://wa.me/{WA}"
    pro = not args.no_pro
    other = "kz" if lang == "ru" else "ru"

    # ── шапка ──
    nav_ids = ["#ready", "#invest", "#plans", "#calc", "#contact"]
    nav = W("icon-list", merge({
        "view": "inline", "icon_list": [{"text": n, "link": link(h), "selected_icon": {"value": "", "library": ""}, "_id": uid()}
                                        for n, h in zip(t["menu"], nav_ids)],
        "space_between": sz(26), "icon_align": "right", "text_color": MIST, "text_color_hover": GOLD,
        "_css_classes": "isma-nav", "hide_tablet": "hidden-tablet", "hide_mobile": "hidden-mobile"},
        typo("icon_typography", 12, 600, 1.7, None, "uppercase")))
    langs = C([
        button("RU", "/" if lang == "kz" else "#", "ghost", True, cls="isma-lang" + (" is-on" if lang == "ru" else ""),
               extra={"text_padding": dim(6, 12), "button_text_color": "#fff" if lang == "ru" else MIST,
                      "background_background": "classic", "background_color": TEAL if lang == "ru" else "rgba(0,0,0,0)"}),
        button("KZ", "/kz/" if lang == "ru" else "#", "ghost", True, cls="isma-lang" + (" is-on" if lang == "kz" else ""),
               extra={"text_padding": dim(6, 12), "button_text_color": "#fff" if lang == "kz" else MIST,
                      "background_background": "classic", "background_color": TEAL if lang == "kz" else "rgba(0,0,0,0)"})],
        content_width="full", flex_direction="row", flex_gap=gap(0), width=sz(112),
        **border(LINE, (1, 1, 1, 1), 99), padding=dim(3), flex_wrap="nowrap")
    header = C([
        W("image", {"image": img("logo.png"), "image_size": "full", "width": sz(87), "_margin": dim(0, 0, 0, 0),
                    "link_to": "custom", "link": link("#top"), "_element_width": "auto"}),
        nav, langs,
        button(T("nav.cta"), "#contact", "fill", True, cls="hdr-cta", extra={"hide_mobile": "hidden-mobile"})],
        content_width="boxed", boxed_width=sz(1232), flex_direction="row", flex_align_items="center", flex_gap=gap(20),
        flex_justify_content="space-between", flex_wrap="nowrap", min_height=sz(68),
        background_background="classic", background_color="rgba(251,248,241,.86)",
        **border(LINE, (0, 0, 1, 0), None), padding=dim(0, 16), _css_classes="isma-header", _element_id="top")

    # ── hero ──
    facts = C([
        C([heading(T(f"hero.f{i}v"), 24, 600, GOLD, "div", "isma-gold isma-count", lh=1.15, t=20, m=18),
           heading(T(f"hero.f{i}l"), 11, 400, MIST, "div", ls=1.54, tr="uppercase", extra={"_margin": dim(4, 0, 0, 0)})],
          content_width="full", flex_direction="column", flex_gap=gap(0), width=sz(33.33, "%"), width_mobile=sz(100, "%"),
          padding=dim(20, 18, 0, 0) if i == 1 else dim(20, 18, 0, 18),
          padding_mobile=dim(16, 0, 0, 0),
          **(border(LINE, (0, 0, 0, 1), None) if i > 1 else {}),
          **({"border_width_mobile": dim(1, 0, 0, 0)} if i > 1 else {}))
        for i in (1, 2, 3)],
        content_width="full", flex_direction="row", flex_direction_mobile="column", flex_wrap="nowrap", flex_gap=gap(0),
        width=sz(100, "%"), _margin=dim(44, 0, 0, 0), **border(LINE, (1, 0, 0, 0), None), **anim("rise", 550, False))
    hero_left = [
        heading(T("hero.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase", extra=anim("rise", 0)),
        heading(TK("hero.h1"), 60, 500, IVORY, "h1", ls=-1.8, lh=1.08, t=44, m=32,
                extra=merge({"_margin": dim(22, 0), "_margin_mobile": dim(16, 0)}, anim("rise", 100))),
        text(f"<p>{T('hero.sub')}</p>", 18, MIST, 400, 1.6, t=16, m=15, extra=merge({"_element_width": "initial", "_element_custom_width": sz(62, "%")}, anim("rise", 250))),
        C([button(T("hero.cta1"), "#plans", "fill"), button(T("hero.cta2"), wa_link, "ghost", blank=True)],
          content_width="full", flex_direction="row", flex_wrap="wrap", flex_gap=gap(14), _margin=dim(34, 0, 0, 0),
          **anim("rise", 400, False)),
        facts]
    badge = C([C(None, content_width="full", background_background="classic", background_color="#5be3a0", _css_classes="dot"),
               heading(T("hero.badge"), 12, 700, IVORY, "div", ls=1.2, tr="uppercase")],
              content_width="full", flex_direction="row", flex_align_items="center", flex_gap=gap(12), flex_wrap="nowrap",
              background_background="classic", background_color="rgba(255,255,255,.88)", padding=dim(14, 20),
              **border(LINE, (1, 1, 1, 1), 16), _css_classes="isma-badge")
    arch = C([W("image", {"image": img("hero.jpg"), "image_size": "full", "width": sz(100, "%"), "height": sz(588),
                          "height_tablet": sz(470), "height_mobile": sz(420), "object_fit": "cover", "object_position": "center left"})],
             content_width="full", _css_classes="isma-arch")
    hero_right = [C([C(None, content_width="full", _css_classes="isma-glass hero-gl"),
                     C(None, content_width="full", _css_classes="isma-ring"),
                     C(None, content_width="full", _css_classes="isma-ring rev"),
                     C(None, content_width="full", _css_classes="isma-frame"),
                     C(None, content_width="full", _css_classes="isma-frame f2"),
                     badge, arch],
                    content_width="full", _css_classes="isma-arch-wrap", **anim("rise", 150, False))]
    hero = section([row2(hero_left, hero_right, 55, 45, 72)], bg=ABYSS, cls="isma-hero", pad=(84, 56, 40),
                   orn=("s tr", "h bl"), extra={"padding": dim(84, 16, 96, 16), "padding_tablet": dim(56, 16, 64, 16),
                                                 "padding_mobile": dim(40, 16, 56, 16)})
    # ── инвестиции ──
    def tile(num, dec_, pre, suf, label, delay):
        val = f'{pre}{dec(num, dec_)}<span class="u">{suf.strip()}</span>' if suf.strip() else f"{pre}{dec(num, dec_)}"
        return C([heading(val, 52, 500, GOLD, "div", "isma-gold isma-count", ls=-1.56, lh=1, t=40, m=34),
                  heading(label, 12, 600, MIST, "div", ls=1.44, lh=1.6, tr="uppercase")],
                 content_width="full", flex_direction="column", flex_gap=gap(10), padding=dim(26, 0, 0, 0),
                 **border(LINE, (1, 0, 0, 0), None), **anim("fadeInUp", delay, False))
    tiles = grid([
        tile(145, 0, "", " млн ₸", T("inv.t1"), 0),
        tile(14.4, 1, "", " млн ₸", T("inv.t2"), 70),
        tile(9.93, 2, "", " %", T("inv.t3"), 140),
        tile(10.1, 1, "≈", f" {T('inv.yr')}", T("inv.t4"), 210)], cols=4, tab=2, mob=1, g=18)
    disc = C([heading(T("inv.dh"), 12, 700, GOLD, "div", ls=1.92, tr="uppercase"),
              heading("130,5 млн ₸", 22, 500, GOLD, "div", "isma-gold isma-count", t=20, m=18),
              heading(T("inv.d2"), 22, 500, IVORY, "div", "isma-count", t=20, m=18),
              heading(T("inv.d3"), 22, 500, IVORY, "div", "isma-count", t=20, m=18)],
             content_width="full", flex_direction="row", flex_wrap="wrap", flex_align_items="center", flex_gap={"column": "34", "row": "14", "isLinked": False, "unit": "px"},
             padding=dim(28, 0), _margin=dim(56, 0, 0, 0), **border(LINE, (1, 0, 1, 0), None), **anim("fadeInUp", 0, False))
    invest_head = C([C(None, content_width="full", _css_classes="isma-solo"),
                     heading(T("inv.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase"),
                     heading(T("inv.h2"), 42, 500, IVORY, "h2", ls=-.84, lh=1.15, t=36, m=26, extra={"_margin": dim(18, 0, 0, 0)})],
                    content_width="full", flex_direction="column", flex_gap=gap(0), _margin=dim(0, 0, 48, 0), _css_classes="isma-invest-head")
    invest = section([divider_orn(), invest_head, tiles, disc,
                      text(f"<p>{T('inv.note')}</p>", 12, MIST, extra={"_margin": dim(20, 0, 0, 0)})],
                     sid="invest", cls="isma-invest", orn=("h tr",))

    # ── преимущества ──
    ICONS = ["fas fa-arrows-alt-v", "fas fa-door-open", "fas fa-border-all", "fas fa-fan", "fas fa-tint", "fas fa-parking",
             "fas fa-map-marker-alt", "fas fa-coins"]
    feats = grid([W("icon-box", merge({
        "selected_icon": {"value": ic, "library": "fa-solid"}, "position": "left", "title_text": ft, "description_text": "",
        "title_size": "div", "primary_color": GOLD, "icon_size": sz(24), "icon_space": sz(16), "title_color": IVORY,
        "_padding": dim(20, 0), **border(LINE, (0, 0, 1, 0), None, "_border"), **anim("fadeInUp", i * 70)},
        typo("title_typography", 15, 400, None, 1.5, None)))
        for i, (ic, ft) in enumerate(zip(ICONS, t["feat"]))], cols=2, tab=2, mob=1, g=0, _margin=dim(38, 0, 0, 0),
        grid_gaps={"column": "36", "row": "0", "isLinked": False, "unit": "px"})
    ready_left = [W("image", {"image": img("interior.jpg"), "image_size": "full", "width": sz(100, "%"), "height": sz(520),
                              "height_tablet": sz(380), "height_mobile": sz(300), "object_fit": "cover",
                              "image_border_radius": dim(28, 28, 28, 200), "image_border_radius_tablet": dim(28, 28, 28, 120),
                              "_css_classes": "isma-photo", "_box_shadow_box_shadow_type": "yes",
                              "_box_shadow_box_shadow": {"horizontal": 0, "vertical": 40, "blur": 70, "spread": -30, "color": "rgba(15,58,61,.4)"},
                              **anim("fadeInUp", 0)})]
    ready_right = [heading(T("adv.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase"),
                   heading(T("adv.h2"), 42, 500, IVORY, "h2", ls=-.84, lh=1.15, t=36, m=26, extra={"_margin": dim(18, 0, 0, 0)}),
                   feats]
    ready = section([row2(ready_left, ready_right, 40, 60, 80)], sid="ready", cls="isma-ready", orn=("s br",))
    ready["elements"][-1]["elements"][1]["settings"]["flex_align_items"] = "stretch"

    # ── спрос / условия ──
    checks = W("icon-list", merge({
        "icon_list": [{"text": d, "selected_icon": {"value": "fas fa-check-circle", "library": "fa-solid"}, "_id": uid()} for d in t["dem"]],
        "space_between": sz(0), "icon_color": "#d8b56a", "icon_size": sz(28), "text_color": IVORY, "text_indent": sz(18),
        "divider": "yes", "divider_style": "solid", "divider_weight": sz(1), "divider_color": LINE, "_margin": dim(34, 0, 0, 0),
        "_padding": dim(0)}, typo("icon_typography", 19, 400, None, 1.5, None, 17, 16)))
    # паддинги между пунктами
    checks["settings"]["icon_align"] = "left"
    terms_items = [C([heading(T(f"terms.{i}v"), 28, 600, GOLD, "div", "isma-gold isma-count", lh=1.15, t=24, m=22),
                      heading(T(f"terms.{i}l"), 11, 400, MIST, "div", ls=1.54, tr="uppercase", extra={"_margin": dim(4, 0, 0, 0)})],
                     content_width="full", flex_direction="column", flex_gap=gap(0), **anim("fadeInUp", i * 70, False)) for i in (1, 2, 3, 4)]
    terms = C([C([C(None, content_width="full", _css_classes="isma-gly"), heading(T("terms.h"), 12, 700, GOLD, "div", ls=2.4, tr="uppercase")],
                 content_width="full", flex_direction="row", flex_align_items="center", flex_gap=gap(12), flex_wrap="nowrap", _margin=dim(0, 0, 22, 0)),
               grid(terms_items, cols=2, tab=2, mob=1, g=22, grid_gaps={"column": "28", "row": "22", "isLinked": False, "unit": "px"})],
              content_width="full", flex_direction="column", flex_gap=gap(0), _margin=dim(48, 0, 0, 0), padding=dim(30, 0, 0, 0),
              **border(LINE, (1, 0, 0, 0), None))
    # маленький орнамент-глиф рядом с заголовком условий (иконка-виджет + css)
    terms["elements"][0]["elements"][0] = W("icon", {"selected_icon": {"value": "fas fa-star", "library": "fa-solid"},
                                                     "primary_color": "#238696", "size": sz(14), "_css_classes": "isma-gly",
                                                     "_element_width": "auto"})
    dem_left = [heading(T("dem.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase"),
                heading(T("dem.h2"), 42, 500, IVORY, "h2", ls=-.84, lh=1.15, t=36, m=26, extra={"_margin": dim(18, 0, 0, 0)}),
                checks, terms]
    dem_right = [C([W("image", {"image": img("aerial.jpg"), "image_size": "full", "width": sz(100, "%"), "height": sz(620),
                                "height_tablet": sz(420), "height_mobile": sz(300), "object_fit": "cover",
                                "image_border_radius": dim(200, 200, 28, 28), "image_border_radius_tablet": dim(28, 28, 28, 120),
                                "_css_classes": "isma-photo", **anim("fadeInUp", 0)})],
                   content_width="full", _css_classes="isma-aerial", sticky="top", sticky_offset=sz(96),
                   sticky_on=["desktop"], width=sz(100, "%"))]
    demand = section([row2(dem_left, dem_right, 55, 45, 72, align="flex-start")], bg=DEEP, sid="demand", cls="isma-demand", orn=("h tl", "s br"))

    # ── планировки ──
    def card(u, i):
        name = f"{t['np']}{u['n']}"
        label = f"{name} · {dec(u['area'])} {t['m2']}"
        ent = f"{t['ent']} " + " / ".join(t["st"][s] for s in u["st"]) + " · " + t["fl"](u["fl"], u["bl"])
        ccls = f"isma-card u-{u['n']} fl-{u['fl']} " + " ".join(f"st-{x}" for x in u["st"])
        return C([
            W("image", {"image": img(f"plan-{u['n']}.png"), "image_size": "full", "width": sz(100, "%"), "height": sz(250),
                        "object_fit": "contain", "_css_classes": "isma-plan", "_margin": dim(26, 26, 6, 26), "_margin_mobile": dim(20, 20, 6, 20)}),
            C([heading(label, 11, 700, "#3a2a07", "div", "isma-pill", ls=.88,
                       extra={"_background_background": "gradient", "_background_color": "#f3dfa6", "_background_color_b": "#f0d894",
                              "_background_gradient_angle": sz(100, "deg"), "_background_color_stop": sz(0, "%"),
                              "_padding": dim(6, 14), "_border_radius": dim(99), "_element_width": "auto"}),
               heading(ent, 12, 400, MIST, "div", ls=.36, lh=1.5, extra={"_margin": dim(8, 0, 0, 0)}),
               heading(f"{fmt(u['ppm'])} ₸/{t['m2']}", 13, 400, MIST, "div"),
               heading(f"{fmt(u['total'])} ₸", 25, 600, GOLD, "div", "isma-gold", lh=1.1, t=22, m=22),
               C([button(t["ask"], "#contact", "fill", True, extra={"button_css_id": f"ask-{u['n']}"}),
                       button(t["calc"], "#calc", "ghost", True, extra={"button_css_id": f"calc-{u['n']}"})],
                 content_width="full", flex_direction="row", flex_wrap="wrap", flex_gap=gap(10), _margin=dim(10, 0, 0, 0))],
              content_width="full", flex_direction="column", flex_gap=gap(6), padding=dim(8, 26, 26, 26), padding_mobile=dim(8, 20, 22, 20)),
        ], content_width="full", flex_direction="column", flex_gap=gap(0), background_background="classic", background_color="#ffffff",
            **border(LINE, (1, 1, 1, 1), 22), **shadow(0, 18, 40, -30, "rgba(15,58,61,.35)"), overflow="hidden",
            _css_classes=ccls, **anim("fadeInUp", (i % 3) * 80, False))
    chips = [button(lbl, "#", "ghost", True, cls="isma-chip" + (" is-on" if k == "all" else ""), extra=merge(
                {"button_css_id": f"chip-{k}", "text_padding": dim(11, 18), "button_text_color": MIST}, typo("typography", 12, 600, .72, 1, "none")))
             for k, lbl in [("all", t["all"]), ("raim", t["chip"]["raim"]), ("ash", t["chip"]["ash"]), ("kald", t["chip"]["kald"]),
                            ("nur", t["chip"]["nur"]), ("f2", t["f2"])]]
    tools = [C(chips, content_width="full", flex_direction="row", flex_wrap="wrap", flex_gap=gap(10), width=sz(70, "%"), width_tablet=sz(100, "%"))]
    if pro:
        tools.append(mini_form("isma-sortform", [{"custom_id": "sort", "field_type": "select", "field_label": "", "width": "100", "required": "",
                                                  "field_options": "\n".join(f"{lbl}|{k}" for k, lbl in zip(["n", "pa", "pd", "s"], t["sort"])),
                                                  "_id": uid()}],
                               extra={"_element_width": "initial", "_element_custom_width": sz(230)}))
    toolbar = C(tools, content_width="full", flex_direction="row", flex_wrap="wrap", flex_justify_content="space-between",
                flex_align_items="center", flex_gap={"column": "14", "row": "12", "isLinked": False, "unit": "px"}, margin=dim(40, 0, 30, 0))
    cards = grid([card(u, i) for i, u in enumerate(UNITS)], cols=3, tab=2, mob=1, g=20)
    plans = section([divider_orn(),
                     heading(T("plans.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase"),
                     heading(T("plans.h2"), 42, 500, IVORY, "h2", ls=-.84, lh=1.15, t=36, m=26, extra={"_margin": dim(18, 0, 0, 0)}),
                     text(f"<p>{T('plans.sub')}</p>", 16, MIST, extra={"_margin": dim(16, 0, 0, 0), "_element_width": "initial", "_element_custom_width": sz(62, "%")}),
                     toolbar, cards], sid="plans", cls="isma-plans", orn=("p tr",))

    # ── калькулятор (пример для НП-1 по тем же формулам, что в оригинале) ──
    u = UNITS[0]
    price = u["total"] * (1 - DISC); down = price * DOWN; month = (price - down) / MONTHS
    income = u["total"] * YIELD; yld = income / price * 100; pb = price / income
    KIND = {"int": fmt, "mln": lambda v: dec(v / 1e6, 1), "pct": lambda v: dec(v, 2), "yr": lambda v: dec(v, 1)}
    def metric(label, val, kind, cls, pre="", suf="", big=False):
        v = f'{pre}<span class="cv" data-k="{kind}" data-v="{val}">{KIND[kind](val)}</span>{suf}'
        return C([heading(label, 11, 700, MIST, "div", ls=1.54, tr="uppercase"),
                  heading(v, 46 if big else 26, 600 if big else 500, IVORY, "div", f"{cls} isma-gold" if big else cls, lh=1.15,
                          t=36 if big else 22, m=34 if big else 20)],
                 content_width="full", flex_direction="column", flex_gap=gap(6),
                 **({"padding": dim(0, 0, 22, 0), **border(LINE, (0, 0, 1, 0), None)} if big else {}))
    cells = grid([
        metric(t["o"][0], u["total"], "int", "o-list", "", " ₸"), metric(t["o"][2], down, "int", "o-down", "", " ₸"),
        metric(t["o"][3], month, "int", "o-month", "", " ₸"), metric(t["o"][4], income, "mln", "o-income", "≈ ", f" {t['mln']} ₸"),
        metric(t["o"][5], yld, "pct", "o-yield", "≈ ", " %"), metric(t["o"][6], pb, "yr", "o-pb", "≈ ", f" {t['yrs']}")],
        cols=2, tab=2, mob=1, g=26, grid_gaps={"column": "28", "row": "26", "isLinked": False, "unit": "px"})
    outs = C([metric(t["oN"], price, "int", "o-price", "", " ₸", True), cells, text(f"<p>{t['fine']}</p>", 12, MIST)],
             content_width="full", flex_direction="column", flex_gap=gap(26), width=sz(60, "%"), width_tablet=sz(100, "%"),
             padding=dim(44), padding_tablet=dim(32), padding_mobile=dim(24), css_classes="isma-calcout")
    if pro:
        calc_in = [mini_form("isma-calcform", [
            {"custom_id": "unit", "field_type": "select", "field_label": T("calc.unit"), "width": "100", "required": "",
             "field_options": "\n".join(f"{t['np']}{x['n']} · {dec(x['area'])} {t['m2']} · {fmt(x['total'])} ₸|{x['n']}" for x in UNITS), "_id": uid()},
            {"custom_id": "disc", "field_type": "checkbox", "field_label": "", "width": "100", "required": "",
             "field_options": T("calc.disc"), "_id": uid()}], show_labels=True)]
    else:
        calc_in = [heading(f"{t['np']}{u['n']} · {dec(u['area'])} {t['m2']} · {fmt(u['total'])} ₸", 15, 500, IVORY, "div")]
    inn = C([*calc_in, heading(T("calc.hint"), 12, 400, MIST, "div", lh=1.5)],
        content_width="full", flex_direction="column", flex_gap=gap(22), width=sz(40, "%"), width_tablet=sz(100, "%"),
        padding=dim(44), padding_tablet=dim(32), padding_mobile=dim(24), **border(LINE, (0, 1, 0, 0), None),
        border_width_tablet=dim(0, 0, 1, 0))
    panel = C([inn, outs], content_width="full", flex_direction="row", flex_direction_tablet="column", flex_wrap="nowrap", flex_gap=gap(0),
              background_background="classic", background_color="#ffffff", **border(LINE, (1, 1, 1, 1), 28), overflow="hidden",
              margin=dim(40, 0, 0, 0), css_classes="isma-calcpanel", **anim("fadeInUp", 0, False))
    calc = section([heading(T("calc.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase"),
                    heading(T("calc.h2"), 42, 500, IVORY, "h2", ls=-.84, lh=1.15, t=36, m=26, extra={"_margin": dim(18, 0, 0, 0)}),
                    panel], bg=DEEP, sid="calc", cls="isma-calc", orn=("p br",))

    # ── контакты ──
    def ci(icon, label, value, acts=None, lib="fa-solid", vlink=None):
        col = [heading(label, 11, 700, MIST, "div", ls=1.76, tr="uppercase"),
               heading(value, 24, 500, IVORY, "div", lh=1.25, t=22, m=18, link_=vlink)]
        if acts: col.append(C(acts, content_width="full", flex_direction="row", flex_wrap="wrap", flex_gap=gap(10), _margin=dim(10, 0, 0, 0)))
        return C([W("icon", {"selected_icon": {"value": icon, "library": lib}, "primary_color": GOLD, "size": sz(26), "_element_width": "auto"}),
                  C(col, content_width="full", flex_direction="column", flex_gap=gap(0))],
                 content_width="full", flex_direction="row", flex_gap=gap(18), flex_wrap="nowrap", flex_align_items="flex-start",
                 padding=dim(20, 0), **border(LINE, (0, 0, 1, 0), None))
    maps = "https://www.google.com/maps/search/?api=1&query=" + urllib.parse.quote("Астана, Ш. Калдаякова 16")
    copy_btn = button(t["copy"], "#", "ghost", True, extra={"button_css_id": "copy-phone"})
    copy_btn["settings"]["link"]["custom_attributes"] = f"data-done|{t['copied']}"
    cl = C([
        ci("fas fa-phone-alt", T("ct.phone"), "+7 771 888 20 20",
           [button("WhatsApp", wa_link, "fill", True, blank=True), button(T("ct.call"), f"tel:+{WA}", "ghost", True), copy_btn]),
        ci("fas fa-map-marker-alt", T("ct.addr"), T("ct.addrv"), [button(T("ct.map"), maps, "ghost", True, blank=True)]),
        ci("fas fa-globe", T("ct.site"), "ismainvest.kz", vlink="https://ismainvest.kz/"),
        ci("fab fa-instagram", "Instagram", "isma_invest", lib="fa-brands", vlink="https://www.instagram.com/isma_invest/")],
        content_width="full", flex_direction="column", flex_gap=gap(0), _margin=dim(34, 0, 0, 0))
    ct_left = [heading(T("ct.eyebrow"), 12, 700, GOLD, "div", "isma-eyebrow", ls=2.64, tr="uppercase"),
               heading(T("ct.h2"), 42, 500, IVORY, "h2", ls=-.84, lh=1.15, t=36, m=26, extra={"_margin": dim(18, 0, 0, 0)}),
               text(f"<p>{T('ct.sub')}</p>", 16, MIST, extra={"_margin": dim(16, 0, 0, 0)}), cl]
    fm = t["form"]
    if pro:
        opts = "\n".join([f"{t['pick']}|"] + [f"{t['np']}{x['n']} · {dec(x['area'])} {t['m2']} · {fmt(x['total'])} ₸|{x['n']}" for x in UNITS])
        form_w = mini_form("isma-form isma-leadform", [
            {"custom_id": "name", "field_type": "text", "field_label": fm["name"], "placeholder": "", "required": "true", "width": "100", "_id": uid()},
            {"custom_id": "phone", "field_type": "tel", "field_label": fm["phone"], "placeholder": "+7 7__ ___ __ __", "required": "true", "width": "100", "_id": uid()},
            {"custom_id": "unit", "field_type": "select", "field_label": fm["unit"], "field_options": opts, "placeholder": "", "required": "", "width": "100", "_id": uid()}],
            show_labels=True, button=fm["send"], extra={"submit_actions": ["email"], "email_subject": "Заявка с сайта ISMA Коммерция", "success_message": fm["ok"]})
        form_kids = [form_w]
    else:
        form_kids = [button(t["ask"], wa_link, "fill", blank=True)]
    form_box = C([C(None, content_width="full", _css_classes="isma-glass form-gl"),
                  heading(T("f.h"), 30, 600, IVORY, "h3", t=26, m=22),
                  *form_kids,
                  heading(T("f.fine"), 12, 400, MIST, "div")],
                 content_width="full", flex_direction="column", flex_gap=gap(18), background_background="classic", background_color="#fff",
                 padding=dim(40), padding_mobile=dim(24), **border(LINE, (1, 1, 1, 1), 28), **shadow(0, 30, 60, -40, "rgba(15,58,61,.4)"),
                 _css_classes="isma-formbox", **anim("fadeInUp", 100, False))
    contact = section([C([divider_orn()], content_width="full"), row2(ct_left, [form_box], 50, 50, 72, align="flex-start")],
                      sid="contact", cls="isma-contact", orn=("p bl", "s tr"))
    contact["elements"][-1]["elements"][1]["settings"]["flex_align_items"] = "stretch"

    # ── подвал / FAB ──
    footer = C([text(f"<p>{T('ft.l')}</p>", 12, MIST), text(f"<p>{T('ft.r')}</p>", 12, MIST)],
               content_width="boxed", boxed_width=sz(1232), flex_direction="row", flex_wrap="wrap", flex_justify_content="space-between",
               flex_align_items="center", flex_gap={"column": "30", "row": "14", "isLinked": False, "unit": "px"},
               padding=dim(34, 16, 110, 16), **border(LINE, (1, 0, 0, 0), None), background_background="classic", background_color=ABYSS)
    band = C(None, content_width="full", _css_classes="isma-band", min_height=sz(84))
    fab = button(T("fab"), wa_link, "none", icon="fab fa-whatsapp", cls="isma-fab", blank=True, extra={
        "button_text_color": "#052b14", "background_background": "classic", "background_color": "#25d366", "hover_color": "#052b14",
        "button_background_hover_color": "#3be37f", "text_padding": dim(14, 22), "align": "right",
        "_position": "fixed", "_offset_orientation_h": "end", "_offset_x_end": sz(16), "_offset_orientation_v": "end", "_offset_y_end": sz(16),
        "_z_index": 55, "_element_width": "auto"})
    fab["settings"]["typography_letter_spacing"] = sz(1.2)

    js = open(os.path.join(HERE, "src/extra.js"), encoding="utf8").read().replace(
        "@@UNITS@@", json.dumps([[x["n"], x["area"], x["ppm"]] for x in UNITS])).replace(
        "@@WAFORM@@", json.dumps(dict(wa=WA, pick=t["pick"], **WAF[lang]), ensure_ascii=False))
    service = C([W("html", {"html": f"<style>\n{CSS}\n</style>\n<script>\n{js}\n</script>"})], content_width="full",
                padding=dim(0), css_classes="isma-service")
    content = [header, hero, invest, ready, demand, plans, calc, contact, band, footer, fab, service]
    title = "ISMA Коммерция" + (" (KZ)" if lang == "kz" else "")
    return {"version": "0.4", "title": title, "type": "page", "content": content,
            "page_settings": {"hide_title": "yes", "template": "elementor_header_footer"}}

CONT_RENAME = {"_css_classes": "css_classes", "_element_id": "css_id"}

def fix(el, top=True):
    st = el["settings"]
    rv = st.pop("_rv", None)
    if el["elType"] == "container":
        el["isInner"] = not top
        for k in list(st):
            if k in CONT_RENAME:
                st[CONT_RENAME[k]] = st.pop(k)
            elif k.startswith(("_margin", "_padding")) or k == "_z_index":
                nk = k[1:]
                v = st.pop(k)
                st.setdefault(nk, v)
        if "padding" not in st:
            st["padding"] = dim(0)
        if st.get("container_type") != "grid" and "flex_gap" not in st:
            st["flex_gap"] = gap(0)
        ck = "css_classes"
    else:
        ck = "_css_classes"
    if rv:
        name, delay = rv
        add = f"isma-rise d{delay}" if name == "rise" else "isma-rv"
        st[ck] = (st.get(ck, "") + " " + add).strip()
    for ch in el.get("elements", []):
        fix(ch, False)

def clean(o):
    if isinstance(o, dict):
        return {k: clean(v) for k, v in o.items() if v is not None}
    if isinstance(o, list):
        return [clean(v) for v in o]
    return o

for lang in ("ru", "kz"):
    data = build(lang)
    for el in data["content"]:
        fix(el)
    data = clean(data)
    json.dump(data, open(os.path.join(HERE, f"isma-{lang}.json"), "w", encoding="utf8"), ensure_ascii=False, separators=(",", ":"))
open(os.path.join(HERE, "isma-extra.css"), "w", encoding="utf8").write(CSS)
print("ok", BASE)
