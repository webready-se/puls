// Refresh the CSRF token every ten minutes, so a login form left open still submits
setInterval(function() {
  fetch('/?csrf').then(function(r) { return r.json(); }).then(function(d) {
    if (d.token) document.querySelector('[name=_token]').value = d.token;
  }).catch(function() {});
}, 600000);
