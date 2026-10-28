/* CoreAI 0.5.79: captcha for guest access and sign-up, solved in the browser.
   The server sends a signed task: find n with SHA-256(salt + n) = challenge.
   This file computes it in small chunks (the page stays responsive) and
   hands the solution back. Own SHA-256, because crypto.subtle exists only on
   HTTPS and is slow per call. No third party, no tracking. */
(function (root) {
  'use strict';

  var K = [
    0x428a2f98, 0x71374491, 0xb5c0fbcf, 0xe9b5dba5, 0x3956c25b, 0x59f111f1, 0x923f82a4, 0xab1c5ed5,
    0xd807aa98, 0x12835b01, 0x243185be, 0x550c7dc3, 0x72be5d74, 0x80deb1fe, 0x9bdc06a7, 0xc19bf174,
    0xe49b69c1, 0xefbe4786, 0x0fc19dc6, 0x240ca1cc, 0x2de92c6f, 0x4a7484aa, 0x5cb0a9dc, 0x76f988da,
    0x983e5152, 0xa831c66d, 0xb00327c8, 0xbf597fc7, 0xc6e00bf3, 0xd5a79147, 0x06ca6351, 0x14292967,
    0x27b70a85, 0x2e1b2138, 0x4d2c6dfc, 0x53380d13, 0x650a7354, 0x766a0abb, 0x81c2c92e, 0x92722c85,
    0xa2bfe8a1, 0xa81a664b, 0xc24b8b70, 0xc76c51a3, 0xd192e819, 0xd6990624, 0xf40e3585, 0x106aa070,
    0x19a4c116, 0x1e376c08, 0x2748774c, 0x34b0bcb5, 0x391c0cb3, 0x4ed8aa4a, 0x5b9cca4f, 0x682e6ff3,
    0x748f82ee, 0x78a5636f, 0x84c87814, 0x8cc70208, 0x90befffa, 0xa4506ceb, 0xbef9a3f7, 0xc67178f2
  ];
  var W = new Array(64);

  function utf8Bytes(text) {
    var out = [];
    for (var i = 0; i < text.length; i++) {
      var c = text.charCodeAt(i);
      if (c < 0x80) out.push(c);
      else if (c < 0x800) out.push(0xc0 | (c >> 6), 0x80 | (c & 63));
      else if (c >= 0xd800 && c < 0xdc00 && i + 1 < text.length) {
        var cp = 0x10000 + ((c - 0xd800) << 10) + (text.charCodeAt(++i) - 0xdc00);
        out.push(0xf0 | (cp >> 18), 0x80 | ((cp >> 12) & 63), 0x80 | ((cp >> 6) & 63), 0x80 | (cp & 63));
      } else out.push(0xe0 | (c >> 12), 0x80 | ((c >> 6) & 63), 0x80 | (c & 63));
    }
    return out;
  }

  function hex8(n) {
    return ('00000000' + (n >>> 0).toString(16)).slice(-8);
  }

  /** SHA-256 of a text as 64 hex characters. */
  function sha256Hex(text) {
    var bytes = utf8Bytes(String(text));
    var bitLength = bytes.length * 8;
    bytes.push(0x80);
    while (bytes.length % 64 !== 56) bytes.push(0);
    var high = Math.floor(bitLength / 0x100000000);
    bytes.push((high >>> 24) & 255, (high >>> 16) & 255, (high >>> 8) & 255, high & 255,
      (bitLength >>> 24) & 255, (bitLength >>> 16) & 255, (bitLength >>> 8) & 255, bitLength & 255);
    var h0 = 0x6a09e667, h1 = 0xbb67ae85, h2 = 0x3c6ef372, h3 = 0xa54ff53a;
    var h4 = 0x510e527f, h5 = 0x9b05688c, h6 = 0x1f83d9ab, h7 = 0x5be0cd19;
    for (var off = 0; off < bytes.length; off += 64) {
      for (var t = 0; t < 16; t++) {
        var j = off + t * 4;
        W[t] = (bytes[j] << 24) | (bytes[j + 1] << 16) | (bytes[j + 2] << 8) | bytes[j + 3];
      }
      for (t = 16; t < 64; t++) {
        var x = W[t - 15], y = W[t - 2];
        var s0 = ((x >>> 7) | (x << 25)) ^ ((x >>> 18) | (x << 14)) ^ (x >>> 3);
        var s1 = ((y >>> 17) | (y << 15)) ^ ((y >>> 19) | (y << 13)) ^ (y >>> 10);
        W[t] = (W[t - 16] + s0 + W[t - 7] + s1) | 0;
      }
      var a = h0, b = h1, c = h2, d = h3, e = h4, f = h5, g = h6, h = h7;
      for (t = 0; t < 64; t++) {
        var S1 = ((e >>> 6) | (e << 26)) ^ ((e >>> 11) | (e << 21)) ^ ((e >>> 25) | (e << 7));
        var ch = (e & f) ^ (~e & g);
        var t1 = (h + S1 + ch + K[t] + W[t]) | 0;
        var S0 = ((a >>> 2) | (a << 30)) ^ ((a >>> 13) | (a << 19)) ^ ((a >>> 22) | (a << 10));
        var maj = (a & b) ^ (a & c) ^ (b & c);
        var t2 = (S0 + maj) | 0;
        h = g; g = f; f = e; e = (d + t1) | 0; d = c; c = b; b = a; a = (t1 + t2) | 0;
      }
      h0 = (h0 + a) | 0; h1 = (h1 + b) | 0; h2 = (h2 + c) | 0; h3 = (h3 + d) | 0;
      h4 = (h4 + e) | 0; h5 = (h5 + f) | 0; h6 = (h6 + g) | 0; h7 = (h7 + h) | 0;
    }
    return hex8(h0) + hex8(h1) + hex8(h2) + hex8(h3) + hex8(h4) + hex8(h5) + hex8(h6) + hex8(h7);
  }

  /**
   * Solves a task from auth.php?action=captcha. Calls onProgress(0..1) now and
   * then; resolves with the payload for the server, or null when no captcha is
   * needed. schedule lets the tests run without timers.
   */
  function solve(task, onProgress, schedule) {
    var later = schedule || function (fn) { setTimeout(fn, 0); };
    return new Promise(function (resolve, reject) {
      if (!task || task.required === false) { resolve(null); return; }
      var max = Math.max(1, Number(task.maxnumber) || 300000);
      var salt = String(task.salt || '');
      var challenge = String(task.challenge || '').toLowerCase();
      if (!/^[0-9a-f]{64}$/.test(challenge) || !salt) { reject(new Error('captcha_invalid')); return; }
      var n = 0;
      var chunk = 4000;
      function step() {
        var end = Math.min(max, n + chunk - 1);
        for (; n <= end; n++) {
          if (sha256Hex(salt + n) === challenge) {
            resolve({ algorithm: task.algorithm || 'SHA-256', challenge: challenge, salt: salt, number: n, signature: task.signature });
            return;
          }
        }
        if (n > max) { reject(new Error('captcha_unsolved')); return; }
        if (typeof onProgress === 'function') onProgress(n / max);
        later(step);
      }
      step();
    });
  }

  root.CoreAICaptcha = { sha256Hex: sha256Hex, solve: solve };
})(typeof window !== 'undefined' ? window : this);
