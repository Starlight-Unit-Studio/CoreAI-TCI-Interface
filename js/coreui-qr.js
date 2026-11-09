/* CoreAI: TCI Interface 0.6.85: QR codes for the authenticator app setup (otpauth:// links).
   A compact QR Code Model 2 encoder after ISO/IEC 18004: byte mode, error correction level M,
   versions 1 to 20, Reed-Solomon over GF(256), all eight masks scored by the standard penalty
   rules. Renders an SVG with a quiet zone of four modules. No network, no library. */
(function () {
  'use strict';

  // Error correction level M: codewords per block and number of blocks, versions 1 to 20.
  var ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26];
  var BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16];
  var FORMAT_M = 0;

  function rawModules(ver) {
    var r = (16 * ver + 128) * ver + 64;
    if (ver >= 2) {
      var n = Math.floor(ver / 7) + 2;
      r -= (25 * n - 10) * n - 55;
      if (ver >= 7) r -= 36;
    }
    return r;
  }
  function dataCodewords(ver) { return Math.floor(rawModules(ver) / 8) - ECC_PER_BLOCK[ver] * BLOCKS[ver]; }

  function gfMul(x, y) {
    var z = 0;
    for (var i = 7; i >= 0; i--) {
      z = (z << 1) ^ ((z >>> 7) * 0x11d);
      z ^= ((y >>> i) & 1) * x;
    }
    return z & 0xff;
  }
  function rsDivisor(degree) {
    var r = [];
    for (var i = 0; i < degree - 1; i++) r.push(0);
    r.push(1);
    var root = 1;
    for (i = 0; i < degree; i++) {
      for (var j = 0; j < r.length; j++) {
        r[j] = gfMul(r[j], root);
        if (j + 1 < r.length) r[j] ^= r[j + 1];
      }
      root = gfMul(root, 0x02);
    }
    return r;
  }
  function rsRemainder(data, divisor) {
    var r = divisor.map(function () { return 0; });
    data.forEach(function (b) {
      var factor = b ^ r.shift();
      r.push(0);
      for (var i = 0; i < divisor.length; i++) r[i] ^= gfMul(divisor[i], factor);
    });
    return r;
  }

  function utf8(text) {
    var out = [];
    var s = unescape(encodeURIComponent(String(text)));
    for (var i = 0; i < s.length; i++) out.push(s.charCodeAt(i));
    return out;
  }

  function encodeData(bytes, ver) {
    var bits = [];
    var push = function (value, len) { for (var i = len - 1; i >= 0; i--) bits.push((value >>> i) & 1); };
    push(4, 4);
    push(bytes.length, ver < 10 ? 8 : 16);
    bytes.forEach(function (b) { push(b, 8); });
    var capacity = dataCodewords(ver) * 8;
    push(0, Math.min(4, capacity - bits.length));
    push(0, (8 - bits.length % 8) % 8);
    var data = [];
    for (var i = 0; i < bits.length; i += 8) {
      var v = 0;
      for (var j = 0; j < 8; j++) v = (v << 1) | bits[i + j];
      data.push(v);
    }
    for (var pad = 0xec; data.length < dataCodewords(ver); pad ^= 0xec ^ 0x11) data.push(pad);
    return data;
  }

  function withEcc(data, ver) {
    var numBlocks = BLOCKS[ver];
    var eccLen = ECC_PER_BLOCK[ver];
    var raw = Math.floor(rawModules(ver) / 8);
    var numShort = numBlocks - raw % numBlocks;
    var shortLen = Math.floor(raw / numBlocks);
    var divisor = rsDivisor(eccLen);
    var blocks = [];
    for (var i = 0, k = 0; i < numBlocks; i++) {
      var dat = data.slice(k, k + shortLen - eccLen + (i < numShort ? 0 : 1));
      k += dat.length;
      var ecc = rsRemainder(dat, divisor);
      if (i < numShort) dat.push(0);
      blocks.push(dat.concat(ecc));
    }
    var out = [];
    for (i = 0; i < blocks[0].length; i++) {
      for (var j = 0; j < blocks.length; j++) {
        if (i !== shortLen - eccLen || j >= numShort) out.push(blocks[j][i]);
      }
    }
    return out;
  }

  function alignmentPositions(ver, size) {
    if (ver === 1) return [];
    var n = Math.floor(ver / 7) + 2;
    var step = Math.ceil((ver * 4 + 4) / (n * 2 - 2)) * 2;
    var r = [6];
    for (var pos = size - 7; r.length < n; pos -= step) r.splice(1, 0, pos);
    return r;
  }

  function build(bytes, ver) {
    var size = ver * 4 + 17;
    var m = [], fn = [];
    for (var y = 0; y < size; y++) { m.push(new Array(size).fill(false)); fn.push(new Array(size).fill(false)); }
    var set = function (x, yy, dark) { m[yy][x] = dark; fn[yy][x] = true; };
    for (var i = 0; i < size; i++) { set(6, i, i % 2 === 0); set(i, 6, i % 2 === 0); }
    [[3, 3], [size - 4, 3], [3, size - 4]].forEach(function (c) {
      for (var dy = -4; dy <= 4; dy++) for (var dx = -4; dx <= 4; dx++) {
        var xx = c[0] + dx, yy = c[1] + dy;
        if (xx >= 0 && xx < size && yy >= 0 && yy < size) set(xx, yy, Math.max(Math.abs(dx), Math.abs(dy)) !== 2 && Math.max(Math.abs(dx), Math.abs(dy)) !== 4);
      }
    });
    var al = alignmentPositions(ver, size);
    for (i = 0; i < al.length; i++) for (var j = 0; j < al.length; j++) {
      if ((i === 0 && j === 0) || (i === 0 && j === al.length - 1) || (i === al.length - 1 && j === 0)) continue;
      for (var ady = -2; ady <= 2; ady++) for (var adx = -2; adx <= 2; adx++) set(al[i] + adx, al[j] + ady, Math.max(Math.abs(adx), Math.abs(ady)) !== 1);
    }
    var drawFormat = function (mask) {
      var data = (FORMAT_M << 3) | mask;
      var rem = data;
      for (var t = 0; t < 10; t++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
      var bits = ((data << 10) | rem) ^ 0x5412;
      var bit = function (n) { return ((bits >>> n) & 1) !== 0; };
      for (t = 0; t <= 5; t++) set(8, t, bit(t));
      set(8, 7, bit(6)); set(8, 8, bit(7)); set(7, 8, bit(8));
      for (t = 9; t < 15; t++) set(14 - t, 8, bit(t));
      for (t = 0; t < 8; t++) set(size - 1 - t, 8, bit(t));
      for (t = 8; t < 15; t++) set(8, size - 15 + t, bit(t));
      set(8, size - 8, true);
    };
    drawFormat(0);
    if (ver >= 7) {
      var rem = ver;
      for (i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
      var vbits = (ver << 12) | rem;
      for (i = 0; i < 18; i++) {
        var b = ((vbits >>> i) & 1) !== 0;
        var a = size - 11 + i % 3, c2 = Math.floor(i / 3);
        set(a, c2, b); set(c2, a, b);
      }
    }
    var cw = withEcc(encodeData(bytes, ver), ver);
    var k = 0;
    for (var right = size - 1; right >= 1; right -= 2) {
      if (right === 6) right = 5;
      for (var vert = 0; vert < size; vert++) for (j = 0; j < 2; j++) {
        var x = right - j;
        var up = ((right + 1) & 2) === 0;
        y = up ? size - 1 - vert : vert;
        if (!fn[y][x] && k < cw.length * 8) { m[y][x] = ((cw[k >>> 3] >>> (7 - (k & 7))) & 1) !== 0; k++; }
      }
    }
    var masked = function (mask) {
      var out = m.map(function (row) { return row.slice(); });
      for (var yy = 0; yy < size; yy++) for (var xx = 0; xx < size; xx++) {
        if (fn[yy][xx]) continue;
        var inv;
        switch (mask) {
          case 0: inv = (xx + yy) % 2 === 0; break;
          case 1: inv = yy % 2 === 0; break;
          case 2: inv = xx % 3 === 0; break;
          case 3: inv = (xx + yy) % 3 === 0; break;
          case 4: inv = (Math.floor(xx / 3) + Math.floor(yy / 2)) % 2 === 0; break;
          case 5: inv = (xx * yy) % 2 + (xx * yy) % 3 === 0; break;
          case 6: inv = ((xx * yy) % 2 + (xx * yy) % 3) % 2 === 0; break;
          default: inv = ((xx + yy) % 2 + (xx * yy) % 3) % 2 === 0;
        }
        if (inv) out[yy][xx] = !out[yy][xx];
      }
      return out;
    };
    var best = null, bestScore = Infinity, bestMask = 0, yy, xx;
    for (var mask = 0; mask < 8; mask++) {
      drawFormat(mask);
      var candidate = masked(mask);
      // Format bits are function modules: copy them from the base matrix.
      for (yy = 0; yy < size; yy++) for (xx = 0; xx < size; xx++) if (fn[yy][xx]) candidate[yy][xx] = m[yy][xx];
      var score = penalty(candidate, size);
      if (score < bestScore) { bestScore = score; best = candidate; bestMask = mask; }
    }
    drawFormat(bestMask);
    for (yy = 0; yy < size; yy++) for (xx = 0; xx < size; xx++) if (fn[yy][xx]) best[yy][xx] = m[yy][xx];
    return best;
  }

  function penalty(m, size) {
    var score = 0, dark = 0, x, y;
    var lines = [];
    for (y = 0; y < size; y++) { lines.push(m[y]); }
    for (x = 0; x < size; x++) { var col = []; for (y = 0; y < size; y++) col.push(m[y][x]); lines.push(col); }
    lines.forEach(function (line) {
      var run = 1;
      for (var i = 1; i <= line.length; i++) {
        if (i < line.length && line[i] === line[i - 1]) { run++; continue; }
        if (run >= 5) score += 3 + (run - 5);
        run = 1;
      }
      var s = line.map(function (v) { return v ? '1' : '0'; }).join('');
      var found = s.match(/(?=(10111010000|00001011101))/g);
      if (found) score += 40 * found.length;
    });
    for (y = 0; y < size - 1; y++) for (x = 0; x < size - 1; x++) {
      var c = m[y][x];
      if (c === m[y][x + 1] && c === m[y + 1][x] && c === m[y + 1][x + 1]) score += 3;
    }
    for (y = 0; y < size; y++) for (x = 0; x < size; x++) if (m[y][x]) dark++;
    var total = size * size;
    score += Math.floor(Math.abs(dark * 20 - total * 10) / total) * 10;
    return score;
  }

  /** The module matrix (true = dark) for a text, or null when it is too long. */
  function matrix(text) {
    var bytes = utf8(text);
    for (var ver = 1; ver <= 20; ver++) {
      var need = 4 + (ver < 10 ? 8 : 16) + bytes.length * 8;
      if (need <= dataCodewords(ver) * 8) return build(bytes, ver);
    }
    return null;
  }

  /** An SVG element with the code, dark on light, quiet zone of four modules. */
  function svg(text, label) {
    var m = matrix(text);
    if (!m) return null;
    var size = m.length + 8;
    var ns = 'http://www.w3.org/2000/svg';
    var el = document.createElementNS(ns, 'svg');
    el.setAttribute('viewBox', '0 0 ' + size + ' ' + size);
    el.setAttribute('shape-rendering', 'crispEdges');
    el.setAttribute('role', 'img');
    el.setAttribute('aria-label', label || 'QR-Code');
    var bg = document.createElementNS(ns, 'rect');
    bg.setAttribute('width', size); bg.setAttribute('height', size); bg.setAttribute('fill', '#ffffff');
    el.appendChild(bg);
    var d = '';
    for (var y = 0; y < m.length; y++) for (var x = 0; x < m.length; x++) if (m[y][x]) d += 'M' + (x + 4) + ' ' + (y + 4) + 'h1v1h-1z';
    var path = document.createElementNS(ns, 'path');
    path.setAttribute('d', d); path.setAttribute('fill', '#000000');
    el.appendChild(path);
    return el;
  }

  var api = {matrix: matrix, svg: svg};
  if (typeof window !== 'undefined') window.CoreAIQR = api;
  if (typeof module !== 'undefined' && module.exports) module.exports = api;
})();
