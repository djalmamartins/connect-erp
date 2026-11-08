/*
 * Vistoria presencial (CN-001): GPS no check-in e nas fotos.
 * Sem JavaScript, os formulários continuam funcionando; o check-in fica "sem localização"
 * e exige justificativa, como manda a regra do servidor.
 * A Geolocation API só funciona em HTTPS (ou localhost).
 */
(function () {
  'use strict';

  function fmt(n) { return Math.round(n).toLocaleString('pt-BR'); }

  function distance(lat1, lng1, lat2, lng2) {
    var r = 6371008.8, rad = Math.PI / 180;
    var dPhi = (lat2 - lat1) * rad, dLambda = (lng2 - lng1) * rad;
    var a = Math.sin(dPhi / 2) * Math.sin(dPhi / 2) + Math.cos(lat1 * rad) * Math.cos(lat2 * rad) * Math.sin(dLambda / 2) * Math.sin(dLambda / 2);
    return 2 * r * Math.asin(Math.min(1, Math.sqrt(a)));
  }

  function locate(onOk, onError) {
    if (!('geolocation' in navigator)) { onError('Este aparelho ou navegador não informa a localização.'); return; }
    if (!window.isSecureContext) { onError('A localização só funciona com o ERP aberto em HTTPS.'); return; }
    navigator.geolocation.getCurrentPosition(onOk, function (err) {
      onError(err && err.code === 1 ? 'Permissão de localização negada. Libere a localização para o ERP nas configurações do navegador.' : 'Não foi possível obter a localização. Vá para um local aberto e tente de novo.');
    }, { enableHighAccuracy: true, timeout: 20000, maximumAge: 0 });
  }

  // Check-in -----------------------------------------------------------------
  var checkin = document.querySelector('[data-insp-checkin]');
  if (checkin) {
    var box = checkin.querySelector('[data-insp-gps]');
    var status = box.querySelector('[data-insp-gps-status]');
    var detail = box.querySelector('[data-insp-gps-detail]');
    var condo = checkin.querySelector('[name="condominium_id"]');
    var refs = JSON.parse(checkin.getAttribute('data-insp-references') || '{}');
    var note = checkin.querySelector('[data-insp-note]');
    var last = null;

    var evaluate = function () {
      if (!last) { return; }
      var ref = refs[condo.value];
      var c = last.coords;
      checkin.querySelector('[name="latitude"]').value = c.latitude.toFixed(7);
      checkin.querySelector('[name="longitude"]').value = c.longitude.toFixed(7);
      checkin.querySelector('[name="accuracy"]').value = Math.round(c.accuracy);
      if (!ref) {
        box.setAttribute('data-state', 'inside');
        status.textContent = 'Localização obtida';
        detail.textContent = 'Precisão de ±' + fmt(c.accuracy) + ' m. Este condomínio ainda não tem ponto cadastrado; a posição será registrada no relatório.';
        note.hidden = true;
        return;
      }
      var d = distance(c.latitude, c.longitude, ref.latitude, ref.longitude);
      var inside = d <= ref.radius_m + Math.min(ref.radius_m, c.accuracy);
      box.setAttribute('data-state', inside ? 'inside' : 'outside');
      status.textContent = inside ? 'Você está no condomínio' : 'Você está a ' + fmt(d) + ' m do condomínio';
      detail.textContent = 'Precisão de ±' + fmt(c.accuracy) + ' m. Raio aceito: ' + fmt(ref.radius_m) + ' m.';
      note.hidden = inside;
    };

    var run = function () {
      box.setAttribute('data-state', 'loading');
      status.textContent = 'Obtendo sua localização…';
      detail.textContent = 'Permita o acesso à localização quando o navegador perguntar.';
      locate(function (pos) { last = pos; evaluate(); }, function (msg) {
        box.setAttribute('data-state', 'error');
        status.textContent = 'Sem localização';
        detail.textContent = msg + ' Para seguir, explique o motivo abaixo.';
        note.hidden = false;
      });
    };
    checkin.querySelector('[data-insp-locate]').addEventListener('click', run);
    condo.addEventListener('change', evaluate);
    run();
  }

  // Posição no momento de cada foto (opcional) ----------------------------------
  document.querySelectorAll('[data-insp-item-form]').forEach(function (form) {
    var input = form.querySelector('input[type="file"]');
    if (!input) { return; }
    input.addEventListener('change', function () {
      if (!input.files || !input.files.length) { return; }
      if (input.files[0].size > 15 * 1024 * 1024) {
        alert('A foto passa de 15 MB. Tire outra com resolução menor.');
        input.value = '';
        return;
      }
      locate(function (pos) {
        form.querySelector('[name="latitude"]').value = pos.coords.latitude.toFixed(7);
        form.querySelector('[name="longitude"]').value = pos.coords.longitude.toFixed(7);
      }, function () {});
    });
  });

  // Ponto do condomínio pela posição atual (tela de configuração) ---------------------
  var here = document.querySelector('[data-insp-use-here]');
  if (here) {
    here.addEventListener('click', function () {
      var form = here.closest('form');
      here.disabled = true;
      locate(function (pos) {
        form.querySelector('[name="latitude"]').value = pos.coords.latitude.toFixed(7);
        form.querySelector('[name="longitude"]').value = pos.coords.longitude.toFixed(7);
        here.disabled = false;
      }, function (msg) { alert(msg); here.disabled = false; });
    });
  }
})();
