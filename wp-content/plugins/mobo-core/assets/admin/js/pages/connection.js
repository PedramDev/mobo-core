(function () {
  'use strict';
  var root = document.getElementById('mobo-core-admin');
  if (!root || !root.classList.contains('mobo-admin--connection')) return;

  var token = root.querySelector('#mobo_new_connection_token');
  if (token) {
    token.addEventListener('input', function () {
      token.value = token.value.replace(/^\s+|\s+$/g, '');
    });
  }
}());
