/**
 * Calendario del panel.
 *
 * Toda la configuracion llega en el data-config del contenedor, no en
 * variables globales impresas por PHP: asi la Content-Security-Policy puede
 * seguir prohibiendo scripts en linea.
 *
 * Este archivo no confia en nada del servidor para construir HTML: todo lo
 * que viene del backend se inserta con textContent, nunca con innerHTML. Un
 * nombre de cliente que contenga <img onerror=...> tiene que verse como
 * texto, no ejecutarse.
 */
(function () {
    'use strict';

    var contenedor = document.getElementById('calendario');
    if (!contenedor) { return; }

    var config = JSON.parse(contenedor.dataset.config);
    var token = document.querySelector('meta[name="csrf-token"]').content;

    var modalEl = document.getElementById('modal-cita');
    var modal = new bootstrap.Modal(modalEl);
    var citaActual = null;

    // ------------------------------------------------------------------
    //  Calendario
    // ------------------------------------------------------------------
    var calendario = new FullCalendar.Calendar(contenedor, {
        initialView: 'timeGridWeek',
        locale: 'es',
        firstDay: config.firstDay,
        height: 'auto',
        nowIndicator: true,
        slotMinTime: config.slotMin,
        slotMaxTime: config.slotMax,
        slotDuration: '00:30:00',
        expandRows: true,
        allDaySlot: false,

        // El calendario se fija en la zona del negocio, no en la del
        // navegador. Sin esto, un administrador conectado desde otro huso
        // veria la agenda desplazada y podria marcar la cita equivocada.
        timeZone: config.timezone,

        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,timeGridDay,listWeek'
        },

        buttonText: {
            today: 'Hoy', month: 'Mes', week: 'Semana',
            day: 'Dia', list: 'Lista'
        },

        events: function (info, exito, fallo) {
            var url = new URL(config.eventsUrl, window.location.origin);
            url.searchParams.set('start', info.startStr);
            url.searchParams.set('end', info.endStr);

            var empleado = document.getElementById('filtro-empleado').value;
            if (empleado) { url.searchParams.set('employee_id', empleado); }

            fetch(url, { headers: { 'Accept': 'application/json' } })
                .then(function (r) {
                    if (r.status === 401) {
                        window.location.reload();   // sesion caducada
                        return [];
                    }
                    if (!r.ok) { throw new Error('HTTP ' + r.status); }
                    return r.json();
                })
                .then(exito)
                .catch(fallo);
        },

        eventClick: function (info) {
            info.jsEvent.preventDefault();
            abrirDetalle(info.event);
        }
    });

    calendario.render();

    document.getElementById('filtro-empleado')
        .addEventListener('change', function () { calendario.refetchEvents(); });

    // ------------------------------------------------------------------
    //  Detalle
    // ------------------------------------------------------------------
    function campo(nombre) {
        return modalEl.querySelector('[data-field="' + nombre + '"]');
    }

    function abrirDetalle(evento) {
        citaActual = evento;
        var p = evento.extendedProps;

        campo('customerName').textContent = p.customerName;
        campo('customerPhone').textContent = p.customerPhone || '—';
        campo('serviceName').textContent = p.serviceName;
        campo('employeeName').textContent = p.employeeName;
        campo('price').textContent = p.price + ' ' + config.currency;
        campo('statusLabel').textContent = p.statusLabel;

        // Las tres horas vienen ya formateadas del servidor, en la zona del
        // negocio. No se recalculan aqui a proposito: FullCalendar entrega
        // Date desplazados (sus campos UTC representan la hora local del
        // calendario), asi que aplicarles toLocaleTimeString con esa misma
        // zona convierte dos veces. Costaba seis horas de desfase.
        campo('horario').textContent = p.hasBuffer
            ? p.serviceStart + ' a ' + p.serviceEnd +
              '  (agenda bloqueada hasta ' + p.blockedEnd + ')'
            : p.serviceStart + ' a ' + p.serviceEnd;

        campo('error').classList.add('d-none');
        modalEl.querySelector('#nota-estado').value = '';

        pintarAcciones(p.transitions, p.labels);
        modal.show();
    }

    function pintarAcciones(transiciones, etiquetas) {
        var caja = campo('acciones');
        caja.textContent = '';

        var hay = transiciones && transiciones.length > 0;
        campo('acciones-wrap').classList.toggle('d-none', !hay);
        campo('sin-acciones').classList.toggle('d-none', hay);

        if (!hay) { return; }

        var estilos = {
            confirmed: 'btn-outline-primary',
            completed: 'btn-success',
            cancelled: 'btn-outline-danger',
            no_show: 'btn-outline-warning'
        };

        transiciones.forEach(function (destino) {
            var boton = document.createElement('button');
            boton.type = 'button';
            boton.className = 'btn btn-sm ' + (estilos[destino] || 'btn-outline-secondary');
            boton.textContent = 'Marcar como ' + (etiquetas[destino] || destino);
            boton.addEventListener('click', function () { cambiarEstado(destino, boton); });
            caja.appendChild(boton);
        });
    }

    function cambiarEstado(destino, boton) {
        if (!citaActual) { return; }

        if (destino === 'cancelled' &&
            !window.confirm('Al cancelar, el horario vuelve a quedar libre para otros clientes. Continuar?')) {
            return;
        }

        var botones = campo('acciones').querySelectorAll('button');
        botones.forEach(function (b) { b.disabled = true; });
        boton.textContent = 'Guardando...';

        fetch(config.statusUrl.replace(':id', citaActual.id), {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                // El token CSRF va por cabecera porque el cuerpo es JSON y
                // no un formulario: CsrfMiddleware acepta las dos vias.
                'X-CSRF-Token': token
            },
            body: JSON.stringify({
                status: destino,
                note: modalEl.querySelector('#nota-estado').value
            })
        })
            .then(function (r) {
                if (r.status === 401 || r.status === 419) {
                    window.location.reload();
                    return null;
                }
                return r.json();
            })
            .then(function (data) {
                if (!data) { return; }

                if (!data.ok) {
                    var err = campo('error');
                    err.textContent = data.error || 'No se pudo actualizar.';
                    err.classList.remove('d-none');
                    botones.forEach(function (b) { b.disabled = false; });
                    boton.textContent = 'Reintentar';
                    return;
                }

                modal.hide();
                calendario.refetchEvents();
            })
            .catch(function () {
                var err = campo('error');
                err.textContent = 'Error de conexion. Revisa tu red e intentalo de nuevo.';
                err.classList.remove('d-none');
                botones.forEach(function (b) { b.disabled = false; });
            });
    }
}());
