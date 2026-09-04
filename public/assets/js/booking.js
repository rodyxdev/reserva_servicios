/**
 * Selector de fecha y hora del paso 3.
 *
 * Pide una ventana de 14 dias de una vez y deja navegar entre ellos sin
 * volver a la red. La alternativa (una peticion por dia) hace que cada clic
 * en una fecha se sienta lento, y en movil con conexion mala es lo que
 * decide que alguien abandone la reserva.
 *
 * Como en el calendario del panel: NADA de innerHTML con datos del
 * servidor, y NINGUNA conversion de zona horaria en el cliente. El servidor
 * manda la hora local ya formateada ('local') y el instante que hay que
 * devolverle ('utc'); aqui solo se copian.
 */
(function () {
    'use strict';

    var caja = document.getElementById('disponibilidad');
    if (!caja) { return; }

    var config = JSON.parse(caja.dataset.config);
    var campoInicio = document.getElementById('starts_at');
    var boton = document.getElementById('btn-confirmar');
    var resumen = document.querySelector('[data-rol="resumen"]');

    var dias = [];
    var diaActivo = null;
    var horarioElegido = campoInicio.value || null;

    cargar(null);

    // ------------------------------------------------------------------
    function cargar(desde) {
        pintarCargando();

        var url = new URL(config.url, window.location.origin);
        if (desde) { url.searchParams.set('desde', desde); }

        fetch(url, { headers: { 'Accept': 'application/json' } })
            .then(function (r) {
                if (r.status === 429) { throw new Error('limite'); }
                if (!r.ok) { throw new Error('HTTP ' + r.status); }
                return r.json();
            })
            .then(function (data) {
                if (!data.ok) { throw new Error(data.error || 'error'); }
                dias = data.dias;
                diaActivo = dias.length ? dias[0].fecha : null;
                pintar(data);
            })
            .catch(function (e) {
                pintarError(e.message === 'limite'
                    ? 'Demasiadas consultas seguidas. Espera un momento y recarga la pagina.'
                    : 'No pudimos cargar los horarios. Revisa tu conexion y recarga la pagina.');
            });
    }

    // ------------------------------------------------------------------
    function limpiar() {
        while (caja.firstChild) { caja.removeChild(caja.firstChild); }
    }

    function pintarCargando() {
        limpiar();
        var d = document.createElement('div');
        d.className = 'text-center text-body-secondary py-4';
        d.textContent = 'Buscando horarios disponibles...';
        caja.appendChild(d);
    }

    function pintarError(mensaje) {
        limpiar();
        var d = document.createElement('div');
        d.className = 'alert alert-warning mb-0';
        d.textContent = mensaje;
        caja.appendChild(d);
    }

    function pintar(data) {
        limpiar();

        if (!dias.length) {
            var vacio = document.createElement('div');
            vacio.className = 'text-center py-4';

            var p = document.createElement('p');
            p.className = 'text-body-secondary mb-3';
            p.textContent = data.fin
                ? 'No quedan horarios disponibles en las proximas semanas.'
                : 'No hay horarios libres en estas dos semanas.';
            vacio.appendChild(p);

            if (!data.fin) {
                vacio.appendChild(botonNavegacion('Ver las dos semanas siguientes', data.siguiente, 'btn-primary'));
            }
            caja.appendChild(vacio);
            return;
        }

        caja.appendChild(pintarDias());
        caja.appendChild(pintarHorarios());
        caja.appendChild(pintarNavegacion(data));
    }

    // ---- Tira de dias -------------------------------------------------
    function pintarDias() {
        var tira = document.createElement('div');
        tira.className = 'tira-dias d-flex gap-2 overflow-auto pb-2 mb-3';
        tira.setAttribute('role', 'tablist');

        dias.forEach(function (dia) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm dia-btn ' +
                (dia.fecha === diaActivo ? 'btn-primary' : 'btn-outline-secondary');
            b.setAttribute('role', 'tab');
            b.setAttribute('aria-selected', dia.fecha === diaActivo ? 'true' : 'false');

            // La etiqueta viene partida en dos lineas: "Lunes 7 de septiembre"
            // -> "Lun" arriba y "7 sept" abajo, pero sin recortar la cadena a
            // ciegas: se usan los trozos que el servidor ya separo por espacio.
            var partes = dia.etiqueta.split(' ');
            var arriba = document.createElement('span');
            arriba.className = 'd-block small';
            arriba.textContent = partes[0].slice(0, 3);

            var abajo = document.createElement('span');
            abajo.className = 'd-block fw-semibold';
            abajo.textContent = partes[1] + ' ' + (partes[3] || '').slice(0, 3);

            b.appendChild(arriba);
            b.appendChild(abajo);

            var cuenta = document.createElement('span');
            cuenta.className = 'd-block dia-cuenta';
            cuenta.textContent = dia.horarios.length + (dia.horarios.length === 1 ? ' hueco' : ' huecos');
            b.appendChild(cuenta);

            b.addEventListener('click', function () {
                diaActivo = dia.fecha;
                pintar({ siguiente: caja.dataset.sig, anterior: caja.dataset.ant,
                         hayAnterior: caja.dataset.hayAnt === '1', fin: caja.dataset.fin === '1' });
            });

            tira.appendChild(b);
        });

        return tira;
    }

    // ---- Horarios del dia activo --------------------------------------
    function pintarHorarios() {
        var envoltorio = document.createElement('div');
        var dia = dias.find(function (d) { return d.fecha === diaActivo; });
        if (!dia) { return envoltorio; }

        var titulo = document.createElement('div');
        titulo.className = 'small text-body-secondary mb-2';
        titulo.textContent = dia.etiqueta;
        envoltorio.appendChild(titulo);

        var rejilla = document.createElement('div');
        rejilla.className = 'rejilla-horas d-flex flex-wrap gap-2';

        dia.horarios.forEach(function (h) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'btn btn-sm ' +
                (h.utc === horarioElegido ? 'btn-primary' : 'btn-outline-primary');
            b.textContent = h.local;
            b.setAttribute('aria-pressed', h.utc === horarioElegido ? 'true' : 'false');

            b.addEventListener('click', function () {
                horarioElegido = h.utc;
                campoInicio.value = h.utc;
                boton.disabled = false;
                resumen.textContent = dia.etiqueta + ' a las ' + h.local + '.';

                rejilla.querySelectorAll('button').forEach(function (otro) {
                    otro.className = 'btn btn-sm btn-outline-primary';
                    otro.setAttribute('aria-pressed', 'false');
                });
                b.className = 'btn btn-sm btn-primary';
                b.setAttribute('aria-pressed', 'true');
            });

            rejilla.appendChild(b);
        });

        envoltorio.appendChild(rejilla);
        return envoltorio;
    }

    // ---- Navegacion entre ventanas ------------------------------------
    function pintarNavegacion(data) {
        // Se guardan en el dataset para que el re-pintado al cambiar de dia
        // no pierda los enlaces de navegacion.
        caja.dataset.sig = data.siguiente || '';
        caja.dataset.ant = data.anterior || '';
        caja.dataset.hayAnt = data.hayAnterior ? '1' : '0';
        caja.dataset.fin = data.fin ? '1' : '0';

        var nav = document.createElement('div');
        nav.className = 'd-flex justify-content-between mt-3 pt-3 border-top';

        if (data.hayAnterior) {
            nav.appendChild(botonNavegacion('← Antes', data.anterior, 'btn-link'));
        } else {
            nav.appendChild(document.createElement('span'));
        }

        if (!data.fin) {
            nav.appendChild(botonNavegacion('Despues →', data.siguiente, 'btn-link'));
        }

        return nav;
    }

    function botonNavegacion(texto, desde, clase) {
        var b = document.createElement('button');
        b.type = 'button';
        b.className = 'btn btn-sm ' + clase;
        b.textContent = texto;
        b.addEventListener('click', function () { cargar(desde); });
        return b;
    }

    // Si se vuelve del servidor con un horario ya elegido (por un error de
    // validacion), el boton debe seguir habilitado.
    if (horarioElegido) { boton.disabled = false; }
}());
