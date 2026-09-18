<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <title>npm package harness</title>
  <style>
    body { font-family: system-ui, sans-serif; margin: 0; padding: 40px; background: #f8fafc; }
    .card { max-width: 420px; padding: 24px; background: #fff; border: 1px solid #e2e8f0; border-radius: 12px; }
    button { margin-top: 12px; padding: 10px 16px; border: 0; border-radius: 8px; background: #4f46e5; color: #fff; cursor: pointer; }
    input { width: 100%; padding: 8px; margin-top: 6px; border: 1px solid #cbd5e1; border-radius: 6px; }
  </style>
</head>
<body>
  <div class="card">
    <h1>Imported via npm</h1>
    <label>Password</label>
    <input type="password" value="should-never-be-captured">
    <button id="break">Break something</button>
    <button id="report">Report a bug</button>
  </div>

  <script type="module">
    // Exactly what a bundler would produce from: import { init, open } from '@buggie/widget'
    import { init, open, identify, isSupported } from '/npm-demo/package';

    window.__npm = { init, open, identify, isSupported };

    await init({
      key: 'pk_d0w1qvkznpjsjvnvg4ygqrnw',
      endpoint: window.location.origin,
      launcher: false,
      identity: { id: 77, email: 'npm@shopper.test', name: 'NPM Tester' },
      release: 'npm-harness-1',
    });

    document.getElementById('report').addEventListener('click', () => open());
    document.getElementById('break').addEventListener('click', () => { null.boom; });
  </script>
</body>
</html>
