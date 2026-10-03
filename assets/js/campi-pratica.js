/* campi-pratica.js - Moduli online della Didattica (modulo.php e istruttoria nel pannello):
 * - tabelle a righe: aggiungi / togli riga;
 * - logica dei campi: "mostra solo se" (data-cond) e "compila in automatico se" (data-auto) sul valore di un altro campo;
 * - scelta di un insegnamento dal catalogo di Ateneo (tipo di corso → corso di studio → a.a. di offerta → insegnamento)
 *   con CFU e S.S.D. compilati da soli nella stessa riga della tabella; se non c'è si scrive a mano.
 * Il server ripete gli stessi controlli (inc/didattica.php, leggi_risposte_modulo). */
(function () {
    'use strict';
    var script = document.currentScript;
    var BASE = (script && script.dataset.base) || '';
    var URL_CAT = BASE + '/cerca_insegnamenti.php';

    // ── Tabelle a righe ──────────────────────────────────────────────────────
    document.addEventListener('click', function (e) {
        var a = e.target.closest('.tab-aggiungi');
        if (a) {
            var tb = a.closest('fieldset').querySelector('tbody'), r = tb.lastElementChild.cloneNode(true);
            r.querySelectorAll('input, select').forEach(function (i) { if (i.tagName === 'SELECT') i.selectedIndex = 0; else i.value = ''; });
            tb.appendChild(r);
            var f = r.querySelector('input, select'); if (f) f.focus();
            return;
        }
        var t = e.target.closest('.tab-togli');
        if (t) {
            var tr = t.closest('tr'), tb2 = tr.parentNode;
            if (tb2.children.length > 1) tr.remove();
            else tr.querySelectorAll('input, select').forEach(function (i) { if (i.tagName === 'SELECT') i.selectedIndex = 0; else i.value = ''; });
        }
    });

    // ── Logica dei campi ─────────────────────────────────────────────────────
    function valoreCampo(form, nome) {
        var box = form.querySelector('.campo-pratica[data-nome="' + nome + '"]');
        if (!box) return null; // campo non in questa pagina: la condizione non si applica
        if (box.hidden) return '';
        var tipo = box.dataset.tipo, el;
        if (tipo === 'checkbox' || tipo === 'dichiarazione') { el = box.querySelector('input[type=checkbox]'); return el && el.checked ? 'Sì' : ''; }
        if (tipo === 'radio') { el = box.querySelector('input[type=radio]:checked'); return el ? el.value : ''; }
        if (tipo === 'multicheck') return Array.prototype.map.call(box.querySelectorAll('input[type=checkbox]:checked'), function (i) { return i.value; }).join(', ');
        if (tipo === 'tabella') return Array.prototype.some.call(box.querySelectorAll('tbody input, tbody select'), function (i) { return i.value.trim() !== ''; }) ? 'righe' : '';
        el = box.querySelector('[name="campo_' + nome + '"]');
        return el ? String(el.value || '').trim() : '';
    }
    function vera(c, v) {
        v = String(v).toLowerCase().trim();
        var att = String(c.valore || '').toLowerCase().trim(), parti = v.split(',').map(function (x) { return x.trim(); });
        switch (c.op) {
            case 'uguale': return v === att || parti.indexOf(att) !== -1;
            case 'diverso': return !(v === att || parti.indexOf(att) !== -1);
            case 'contiene': return att !== '' && v.indexOf(att) !== -1;
            case 'compilato': return v !== '';
            case 'vuoto': return v === '';
        }
        return true;
    }
    function mostra(box, si) {
        if (box.hidden === !si) return;
        box.hidden = !si;
        box.querySelectorAll('input, select, textarea').forEach(function (i) {
            if (!si && i.required) { i.dataset.req = '1'; i.required = false; }
            if (si && i.dataset.req === '1') i.required = true;
        });
    }
    function imposta(box, testo) {
        var tipo = box.dataset.tipo;
        if (tipo === 'checkbox' || tipo === 'dichiarazione') { var c = box.querySelector('input[type=checkbox]'); if (c) c.checked = testo !== ''; return; }
        if (tipo === 'radio' || tipo === 'multicheck') {
            box.querySelectorAll('input').forEach(function (i) { i.checked = testo.split(',').map(function (x) { return x.trim(); }).indexOf(i.value) !== -1; });
            return;
        }
        var el = box.querySelector('[name="campo_' + box.dataset.nome + '"]');
        if (el && el.value !== testo) { el.value = testo; el.dispatchEvent(new Event('change', { bubbles: true })); }
    }
    function aggiornaLogica(form) {
        // Due passaggi: un campo può dipendere da uno che a sua volta dipende da un altro
        for (var giro = 0; giro < 2; giro++) {
            form.querySelectorAll('.campo-pratica[data-cond]').forEach(function (box) {
                var c = JSON.parse(box.dataset.cond), v = valoreCampo(form, c.nome);
                mostra(box, v === null || vera(c, v));
            });
        }
        form.querySelectorAll('.campo-pratica[data-auto]').forEach(function (box) {
            var a = JSON.parse(box.dataset.auto), v = valoreCampo(form, a.nome), si = !box.hidden && v !== null && vera(a, v);
            box.querySelectorAll('input:not([type=hidden]), select, textarea').forEach(function (i) { i.classList.toggle('bg-light', si); if (i.tagName !== 'SELECT' && i.type !== 'checkbox' && i.type !== 'radio') i.readOnly = si; });
            if (si) imposta(box, a.imposta || '');
            box.dataset.autoAttivo = si ? '1' : '';
        });
    }
    function prepara(form) {
        if (form.dataset.logica) return;
        form.dataset.logica = '1';
        form.addEventListener('input', function () { aggiornaLogica(form); });
        form.addEventListener('change', function () { aggiornaLogica(form); });
        form.addEventListener('submit', function (e) {
            // Scelte multiple obbligatorie: almeno una casella (solo se il campo è visibile)
            var manca = Array.prototype.find.call(form.querySelectorAll('fieldset[data-almeno-uno]'), function (fs) {
                var box = fs.closest('.campo-pratica');
                return !(box && box.hidden) && !fs.querySelector('input:checked');
            });
            if (manca) { e.preventDefault(); manca.scrollIntoView({ block: 'center' }); alert('Scegli almeno una opzione in: ' + manca.querySelector('legend').textContent.replace('*', '').trim()); }
        });
        aggiornaLogica(form);
    }
    function avvia() {
        document.querySelectorAll('.campo-pratica').forEach(function (b) { var f = b.closest('form'); if (f) prepara(f); });
    }
    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', avvia); else avvia();

    // ── Scelta dell'insegnamento dal catalogo di Ateneo ──────────────────────
    var cache = {}, dlg = null, bersaglio = null;
    function json(q) {
        if (cache[q]) return Promise.resolve(cache[q]);
        return fetch(URL_CAT + '?' + q, { credentials: 'same-origin', headers: { 'Accept': 'application/json' } })
            .then(function (r) { if (!r.ok) throw new Error(r.status); return r.json(); })
            .then(function (j) { cache[q] = j; return j; });
    }
    function opt(sel, valore, testo, dati) {
        var o = document.createElement('option'); o.value = valore; o.textContent = testo;
        if (dati) Object.keys(dati).forEach(function (k) { o.dataset[k] = dati[k]; });
        sel.appendChild(o); return o;
    }
    function svuota(sel, testo) { sel.innerHTML = ''; opt(sel, '', testo); sel.disabled = true; }
    function aa(anno) { anno = parseInt(anno, 10); return anno + '/' + (anno + 1); }
    function cfuTesto(c) { return c === null || c === undefined ? '' : String(c).replace('.', ',').replace(/,0$/, ''); }
    function creaDialog() {
        dlg = document.createElement('dialog');
        dlg.className = 'border-0 rounded-3 shadow p-0';
        dlg.style.maxWidth = '640px'; dlg.style.width = '96%';
        dlg.setAttribute('aria-labelledby', 'insDlgTit');
        dlg.innerHTML = '<form method="dialog" class="p-3">'
            + '<h2 class="h5 fw-bold mb-1" id="insDlgTit">Scegli l\'insegnamento</h2>'
            + '<p class="small text-secondary mb-3">Dal catalogo dei corsi di studio dell\'Università della Calabria. Se non lo trovi chiudi e scrivilo a mano.</p>'
            + '<div class="row g-2">'
            + '<div class="col-md-6"><label class="form-label small fw-bold mb-0" for="insTipo">Tipo di corso</label><select class="form-select form-select-sm" id="insTipo"></select></div>'
            + '<div class="col-md-6"><label class="form-label small fw-bold mb-0" for="insAa">Anno accademico di offerta</label><select class="form-select form-select-sm" id="insAa"></select></div>'
            + '<div class="col-12"><label class="form-label small fw-bold mb-0" for="insCorso">Corso di studio</label><select class="form-select form-select-sm" id="insCorso"></select></div>'
            + '<div class="col-12"><label class="form-label small fw-bold mb-0" for="insCerca">Insegnamento</label><input type="search" class="form-control form-control-sm mb-1" id="insCerca" placeholder="Filtra per nome" disabled>'
            + '<select class="form-select form-select-sm" id="insIns" size="8"></select><div class="small text-secondary mt-1" id="insStato" aria-live="polite"></div></div>'
            + '</div><div class="d-flex gap-2 justify-content-end mt-3">'
            + '<button type="button" class="btn btn-sm btn-outline-secondary" id="insAnnulla">Annulla</button>'
            + '<button type="button" class="btn btn-sm btn-primary fw-bold" id="insUsa" disabled>Usa questo insegnamento</button></div></form>';
        document.body.appendChild(dlg);
        var tipo = dlg.querySelector('#insTipo'), corso = dlg.querySelector('#insCorso'), anno = dlg.querySelector('#insAa'),
            ins = dlg.querySelector('#insIns'), cerca = dlg.querySelector('#insCerca'), stato = dlg.querySelector('#insStato'), usa = dlg.querySelector('#insUsa');
        dlg.querySelector('#insAnnulla').addEventListener('click', function () { dlg.close(); });
        tipo.addEventListener('change', function () {
            svuota(corso, 'Caricamento…'); svuota(anno, '—'); svuota(ins, '—'); cerca.disabled = true; usa.disabled = true;
            if (!tipo.value) { svuota(corso, '—'); return; }
            json('azione=corsi&tipo=' + encodeURIComponent(tipo.value)).then(function (el) {
                svuota(corso, el.length ? '-- scegli il corso --' : 'Nessun corso'); corso.disabled = !el.length;
                el.forEach(function (c) { opt(corso, c.codice, c.nome + (c.dipartimento ? ' – ' + c.dipartimento : ''), { anni: (c.anni || []).join(','), nome: c.nome }); });
            }).catch(function () { svuota(corso, 'Catalogo non disponibile'); });
        });
        corso.addEventListener('change', function () {
            svuota(anno, '-- anno di offerta --'); svuota(ins, '—'); cerca.disabled = true; usa.disabled = true;
            var o = corso.selectedOptions[0]; if (!o || !o.value) return;
            (o.dataset.anni || '').split(',').filter(Boolean).forEach(function (a) { opt(anno, a, aa(a)); });
            anno.disabled = false;
        });
        anno.addEventListener('change', function () {
            svuota(ins, 'Caricamento…'); cerca.disabled = true; usa.disabled = true; stato.textContent = '';
            if (!anno.value) { svuota(ins, '—'); return; }
            json('azione=insegnamenti&cds=' + encodeURIComponent(corso.value) + '&aa=' + encodeURIComponent(anno.value)).then(function (el) {
                ins.innerHTML = ''; ins.disabled = !el.length; cerca.disabled = !el.length; cerca.value = '';
                stato.textContent = el.length ? el.length + ' insegnamenti' : 'Nessun insegnamento per questo anno: prova un altro anno o scrivilo a mano.';
                el.forEach(function (i) {
                    opt(ins, i.id, i.nome + (i.anno ? ' · ' + i.anno + '° anno' : '') + (i.cfu !== null ? ' · ' + cfuTesto(i.cfu) + ' CFU' : '') + (i.ssd ? ' · ' + i.ssd : ''),
                        { nome: i.nome, cfu: i.cfu === null ? '' : i.cfu, ssd: i.ssd || '' });
                });
            }).catch(function () { svuota(ins, 'Catalogo non disponibile'); });
        });
        cerca.addEventListener('input', function () {
            var q = cerca.value.toLowerCase();
            Array.prototype.forEach.call(ins.options, function (o) { o.hidden = q && o.textContent.toLowerCase().indexOf(q) === -1; });
        });
        ins.addEventListener('change', function () { usa.disabled = !ins.value; });
        ins.addEventListener('dblclick', function () { if (ins.value) usa.click(); });
        usa.addEventListener('click', function () {
            var o = ins.selectedOptions[0]; if (!o) return;
            var co = corso.selectedOptions[0], dati = { id: parseInt(o.value, 10), nome: o.dataset.nome, corso: co ? co.dataset.nome : '', aa: anno.value ? aa(anno.value) : '',
                cfu: o.dataset.cfu === '' ? null : parseFloat(o.dataset.cfu), ssd: o.dataset.ssd };
            applica(dati); dlg.close();
        });
        json('azione=tipi').then(function (el) {
            svuota(tipo, '-- tipo di corso --'); tipo.disabled = false;
            el.forEach(function (t) { opt(tipo, t.tipo, t.nome); });
        }).catch(function () { svuota(tipo, 'Catalogo non disponibile'); });
        svuota(corso, '—'); svuota(anno, '—'); svuota(ins, '—');
    }
    function scrivi(el, v) { if (el) { el.value = v; el.dispatchEvent(new Event('change', { bubbles: true })); } }
    function applica(d) {
        if (!bersaglio) return;
        var tr = bersaglio.closest('tr');
        if (tr) {
            // Riga di una tabella: nome nella cella dell'insegnamento, CFU e S.S.D. nelle colonne di quel tipo
            scrivi(bersaglio.closest('td').querySelector('input[type=text]'), d.nome + (d.corso ? ' – ' + d.corso : ''));
            if (d.cfu !== null) tr.querySelectorAll('[data-col="cfu"]').forEach(function (i) { if (!i.value) scrivi(i, d.cfu); });
            if (d.ssd) tr.querySelectorAll('[data-col="ssd"]').forEach(function (i) { if (!i.value) scrivi(i, d.ssd); });
            return;
        }
        var box = bersaglio.closest('.campo-pratica') || bersaglio.parentNode;
        scrivi(box.querySelector('.ins-testo'), d.nome + (d.corso ? ' – ' + d.corso : '') + (d.aa ? ' (a.a. ' + d.aa + ')' : '') + (d.cfu !== null ? ' · ' + cfuTesto(d.cfu) + ' CFU' : '') + (d.ssd ? ' · ' + d.ssd : ''));
        var m = box.querySelector('.ins-meta'); if (m) m.value = JSON.stringify(d);
    }
    document.addEventListener('click', function (e) {
        var b = e.target.closest('.ins-scegli');
        if (!b) return;
        e.preventDefault();
        bersaglio = b;
        if (!dlg) creaDialog();
        if (typeof dlg.showModal === 'function') dlg.showModal(); else dlg.setAttribute('open', '');
    });
    // Scritto a mano: il collegamento al catalogo non vale più
    document.addEventListener('input', function (e) {
        if (e.target.classList && e.target.classList.contains('ins-testo')) {
            var m = e.target.closest('.campo-pratica'); m = m && m.querySelector('.ins-meta'); if (m) m.value = '';
        }
    });
})();
