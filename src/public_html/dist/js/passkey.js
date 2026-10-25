/*
 * Passkeys (WebAuthn) for the Reqad login page, the Security page and the
 * "Confirm it's you" dialog. The server sends the options with binary values
 * as base64url strings (app/auth/auth.php); this turns them into the
 * ArrayBuffers the browser API wants, runs the ceremony and returns the
 * answer with base64url strings again, ready to POST.
 *
 *   ReqadPasskey.supported()            -> bool
 *   ReqadPasskey.get(options)           -> Promise({id, clientDataJSON, authenticatorData, signature})
 *   ReqadPasskey.create(options)        -> Promise({clientDataJSON, attestationObject})
 *   ReqadPasskey.message(err)           -> text for a failed / cancelled ceremony
 */
(function () {
	function toBuf(s) {
		s = String(s).replace(/-/g, '+').replace(/_/g, '/');
		while (s.length % 4) s += '=';
		var b = atob(s), a = new Uint8Array(b.length);
		for (var i = 0; i < b.length; i++) a[i] = b.charCodeAt(i);
		return a.buffer;
	}
	function toStr(buf) {
		var a = new Uint8Array(buf), s = '';
		for (var i = 0; i < a.length; i++) s += String.fromCharCode(a[i]);
		return btoa(s).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
	}
	function ids(list) {
		(list || []).forEach(function (c) { c.id = toBuf(c.id); });
	}

	window.ReqadPasskey = {
		supported: function () {
			return !!(window.PublicKeyCredential && navigator.credentials && window.isSecureContext);
		},
		get: function (o) {
			var pk = o.publicKey;
			pk.challenge = toBuf(pk.challenge);
			ids(pk.allowCredentials);
			return navigator.credentials.get({ publicKey: pk }).then(function (c) {
				return {
					id: toStr(c.rawId),
					clientDataJSON: toStr(c.response.clientDataJSON),
					authenticatorData: toStr(c.response.authenticatorData),
					signature: toStr(c.response.signature)
				};
			});
		},
		create: function (o) {
			var pk = o.publicKey;
			pk.challenge = toBuf(pk.challenge);
			pk.user.id = toBuf(pk.user.id);
			ids(pk.excludeCredentials);
			return navigator.credentials.create({ publicKey: pk }).then(function (c) {
				return {
					clientDataJSON: toStr(c.response.clientDataJSON),
					attestationObject: toStr(c.response.attestationObject)
				};
			});
		},
		message: function (e) {
			var n = e && e.name;
			if (n === 'NotAllowedError' || n === 'AbortError')
				return 'Cancelled, or no passkey for this panel was chosen.';
			if (n === 'InvalidStateError')
				return 'This device already has a passkey for this panel.';
			if (n === 'SecurityError')
				return 'Passkeys need the panel to be opened by its hostname, not its IP address.';
			return (e && e.message) || 'The passkey did not work.';
		}
	};
})();
