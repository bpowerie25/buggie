<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>Acme Store — widget harness</title>
  {{-- Local development only; see routes/web.php --}}
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; padding: 40px; background: #f8fafc; color: #0f172a; }
    .card { max-width: 420px; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
    label { display: block; font-size: 13px; font-weight: 600; margin: 12px 0 4px; }
    input { width: 100%; padding: 8px; border: 1px solid #cbd5e1; border-radius: 6px; }
    button { margin-top: 16px; padding: 10px 16px; border: 0; border-radius: 8px; background: #0f172a; color: #fff; cursor: pointer; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Checkout</h1>
    <label for="card">Card number</label>
    <input id="card" value="4242 4242 4242 4242">
    <label for="pw">Password</label>
    <input id="pw" type="password" value="hunter2-should-never-be-captured">
    <label for="secret">Internal note</label>
    <input id="secret" data-buggy-redact value="REDACT-ME-TOO">
    <button id="pay">Pay now</button>
  </div>

  <script>
    document.getElementById('pay').addEventListener('click', () => {
      console.warn('Deprecated payment API in use');
      fetch('/api/cart/total', { method: 'POST' }).catch(() => {});
      // Deliberate failure, so the widget has a real error to attach.
      null.total;
    });
  </script>
  <script src="{{ $snippetUrl }}" async></script>
</body>
</html>
