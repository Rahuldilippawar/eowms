/**
 * EOWMS WebAuthn (Fingerprint/Face ID) — Client-side helper
 */
function b64urlToBuffer(b64url) {
  const pad = '='.repeat((4 - (b64url.length % 4)) % 4);
  const base64 = (b64url + pad).replace(/-/g, '+').replace(/_/g, '/');
  const raw = atob(base64);
  const buf = new Uint8Array(raw.length);
  for (let i = 0; i < raw.length; i++) { buf[i] = raw.charCodeAt(i); }
  return buf.buffer;
}
function bufferToB64url(buf) {
  const bytes = new Uint8Array(buf);
  let str = '';
  for (let i = 0; i < bytes.byteLength; i++) { str += String.fromCharCode(bytes[i]); }
  return btoa(str).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
}

/** Profile पानावरून — सध्याचं डिव्हाइस Fingerprint/Face ID साठी नोंदवा */
async function eowmsRegisterFingerprint(rootPath, deviceLabel) {
  rootPath = rootPath || '';
  if (!window.PublicKeyCredential) {
    alert('हा Browser Fingerprint/Face ID Login ला समर्थन देत नाही — कृपया Chrome/Safari चा नवीन version वापरा.');
    return;
  }
  try {
    const optRes = await fetch(rootPath + 'webauthn-register-options.php');
    const options = await optRes.json();
    if (options.message) { throw new Error(options.message); }

    options.challenge = b64urlToBuffer(options.challenge);
    options.user.id = b64urlToBuffer(options.user.id);

    const credential = await navigator.credentials.create({ publicKey: options });

    const payload = {
      id: bufferToB64url(credential.rawId),
      clientDataJSON: bufferToB64url(credential.response.clientDataJSON),
      attestationObject: bufferToB64url(credential.response.attestationObject),
      deviceLabel: deviceLabel || (navigator.userAgentData ? navigator.userAgentData.platform : navigator.platform) || 'माझं डिव्हाइस',
    };

    const verifyRes = await fetch(rootPath + 'webauthn-register-verify.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const result = await verifyRes.json();
    if (result.success) {
      alert('✓ ' + result.message);
      window.location.reload();
    } else {
      alert('चूक: ' + result.message);
    }
  } catch (err) {
    if (err.name === 'NotAllowedError') {
      alert('Fingerprint/Face ID नोंदणी रद्द केली गेली किंवा वेळ संपली.');
    } else {
      alert('चूक: ' + err.message);
    }
  }
}

/** Login पानावरून — नोंदवलेल्या Fingerprint/Face ID ने Login करा */
async function eowmsLoginWithFingerprint(email) {
  if (!window.PublicKeyCredential) {
    alert('हा Browser Fingerprint/Face ID Login ला समर्थन देत नाही — कृपया Chrome/Safari चा नवीन version वापरा.');
    return;
  }
  if (!email) {
    alert('कृपया आधी तुमचा Email टाका, मग Fingerprint बटण दाबा.');
    return;
  }
  try {
    const optRes = await fetch('webauthn-login-options.php', {
      method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: 'email=' + encodeURIComponent(email),
    });
    const options = await optRes.json();
    if (options.message && !options.challenge) { throw new Error(options.message); }

    options.challenge = b64urlToBuffer(options.challenge);
    options.allowCredentials = (options.allowCredentials || []).map((c) => ({ ...c, id: b64urlToBuffer(c.id) }));

    const assertion = await navigator.credentials.get({ publicKey: options });

    const payload = {
      id: bufferToB64url(assertion.rawId),
      clientDataJSON: bufferToB64url(assertion.response.clientDataJSON),
      authenticatorData: bufferToB64url(assertion.response.authenticatorData),
      signature: bufferToB64url(assertion.response.signature),
    };

    const verifyRes = await fetch('webauthn-login-verify.php', {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(payload),
    });
    const result = await verifyRes.json();
    if (result.success) {
      window.location.href = result.redirect;
    } else {
      alert('Login अयशस्वी: ' + result.message);
    }
  } catch (err) {
    if (err.name === 'NotAllowedError') {
      alert('Fingerprint/Face ID Login रद्द केलं गेलं किंवा वेळ संपली.');
    } else {
      alert('चूक: ' + err.message);
    }
  }
}
