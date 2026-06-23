<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Proxmox Backup Server - Datastore</title>
    <style>
        :root {
            --bg-dark: #121212;
            --bg-panel: #1e1e1e;
            --bg-header: #212121;
            --border-color: #333333;
            --text-main: #e0e0e0;
            --text-muted: #888888;
            --accent: #ff6f00;
            /* Proxmox Orange */
            --accent-hover: #ff8c00;
            --btn-gray: #333;
            --btn-gray-hover: #444;
            --success: #388e3c;
            --danger: #d32f2f;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
            background-color: var(--bg-dark);
            color: var(--text-main);
            display: flex;
            height: 100vh;
            overflow: hidden;
        }

        .sidebar {
            width: 250px;
            background-color: var(--bg-panel);
            border-right: 1px solid var(--border-color);
            display: flex;
            flex-direction: column;
        }

        .brand {
            padding: 15px 20px;
            background-color: var(--bg-header);
            border-bottom: 1px solid var(--border-color);
            font-weight: bold;
            font-size: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .brand-logo {
            width: 20px;
            height: 20px;
            background: var(--accent);
            border-radius: 3px;
        }

        .nav-item {
            padding: 12px 20px;
            cursor: pointer;
            border-bottom: 1px solid var(--border-color);
            font-size: 14px;
            transition: background 0.2s;
        }

        .nav-item:hover,
        .nav-item.active {
            background-color: rgba(255, 111, 0, 0.1);
            border-left: 3px solid var(--accent);
        }

        .main-content {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .topbar {
            height: 50px;
            background-color: var(--bg-panel);
            border-bottom: 1px solid var(--border-color);
            padding: 0 20px;
            display: flex;
            align-items: center;
        }

        .content-area {
            padding: 20px;
            flex: 1;
            overflow-y: auto;
        }

        .panel {
            background-color: var(--bg-panel);
            border: 1px solid var(--border-color);
            border-radius: 4px;
            margin-bottom: 20px;
        }

        .panel-header {
            padding: 10px 15px;
            border-bottom: 1px solid var(--border-color);
            font-weight: bold;
            background-color: var(--bg-header);
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .panel-body {
            padding: 15px;
        }

        .form-group {
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            color: var(--text-muted);
            font-size: 12px;
        }

        .form-control {
            width: 100%;
            padding: 8px 12px;
            background-color: var(--bg-dark);
            border: 1px solid var(--border-color);
            color: var(--text-main);
            border-radius: 3px;
        }

        .btn {
            background-color: var(--btn-gray);
            color: white;
            border: 1px solid var(--border-color);
            padding: 6px 12px;
            border-radius: 3px;
            cursor: pointer;
            font-size: 13px;
            transition: background 0.2s;
        }

        .btn:hover {
            background-color: var(--btn-gray-hover);
        }

        .btn-primary {
            background-color: var(--accent);
            border-color: var(--accent);
        }

        .btn-primary:hover {
            background-color: var(--accent-hover);
        }

        .btn-danger {
            background-color: var(--danger);
            border-color: var(--danger);
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            padding: 8px 10px;
            border-bottom: 1px solid var(--border-color);
            text-align: left;
        }

        th {
            background-color: var(--bg-header);
            font-weight: normal;
            color: var(--text-muted);
        }

        tr:hover {
            background-color: rgba(255, 255, 255, 0.02);
        }

        .console {
            background: black;
            color: #0f0;
            padding: 10px;
            font-family: monospace;
            border-radius: 3px;
            height: 200px;
            overflow-y: auto;
            white-space: pre-wrap;
            font-size: 12px;
        }

        .tab-content {
            display: none;
        }

        .tab-content.active {
            display: block;
        }
    </style>
</head>

<body>

    <div class="sidebar">
        <div class="brand">
            <div class="brand-logo"></div>
            Proxmox Backup
        </div>
        <div class="nav-item active" onclick="switchTab('datastore')">Datastore s3-garage</div>
        <div class="nav-item" onclick="switchTab('config')">Datastore Configuration</div>
        <div class="nav-item" onclick="window.location.href='/'">← Back to SaaS</div>
    </div>

    <div class="main-content">
        <div class="topbar">
            <span>Datastore 's3-garage'</span>
        </div>

        <div class="content-area">

            <!-- DATASTORE TAB -->
            <div id="tab-datastore" class="tab-content active">
                <div class="panel">
                    <div class="panel-header">
                        <span>Snapshots</span>
                        <div>
                            <button class="btn btn-primary" onclick="initDatastore()">Format/Init</button>
                            <button class="btn btn-primary" onclick="takeSnapshot()">Backup Now</button>
                            <button class="btn" onclick="loadSnapshots()">Refresh</button>
                        </div>
                    </div>
                    <div class="panel-body" style="padding: 0;">
                        <table id="snapshots-table">
                            <thead>
                                <tr>
                                    <th>Snapshot ID</th>
                                    <th>Timestamp</th>
                                    <th>Paths</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr>
                                    <td colspan="4" style="text-align: center; padding: 20px;">Loading Snapshots...</td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-header">Task Log</div>
                    <div class="panel-body">
                        <div class="console" id="console-output">Ready.</div>
                    </div>
                </div>
            </div>

            <!-- CONFIGURATION TAB -->
            <div id="tab-config" class="tab-content">
                <div class="panel">
                    <div class="panel-header">S3 Target Configuration (Garage)</div>
                    <div class="panel-body">
                        <form id="config-form" onsubmit="saveConfig(event)">
                            <div class="form-group">
                                <label>AWS_ENDPOINT (e.g. http://127.0.0.1:3900)</label>
                                <input type="text" class="form-control" name="AWS_ENDPOINT" required>
                            </div>
                            <div class="form-group">
                                <label>AWS_BUCKET</label>
                                <input type="text" class="form-control" name="AWS_BUCKET" required>
                            </div>
                            <div class="form-group">
                                <label>AWS_ACCESS_KEY_ID</label>
                                <input type="text" class="form-control" name="AWS_ACCESS_KEY_ID" required>
                            </div>
                            <div class="form-group">
                                <label>AWS_SECRET_ACCESS_KEY</label>
                                <input type="text" class="form-control" name="AWS_SECRET_ACCESS_KEY" required>
                            </div>
                            <div class="form-group">
                                <label>RESTIC_PASSWORD (Encryption Key)</label>
                                <input type="password" class="form-control" name="RESTIC_PASSWORD"
                                    placeholder="garage-restic-secret">
                            </div>
                            <button type="submit" class="btn btn-primary">Save Datastore Config</button>
                        </form>
                    </div>
                </div>
            </div>

        </div>
    </div>

    <script>
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.nav-item').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tabId).classList.add('active');
            event.currentTarget.classList.add('active');
        }

        function logMsg(msg, isErr = false) {
            const cons = document.getElementById('console-output');
            const color = isErr ? '#ff3333' : '#00ff00';
            cons.innerHTML += `<div style="color: ${color}">> ${msg}</div>`;
            cons.scrollTop = cons.scrollHeight;
        }

        async function api(url, method = 'GET', body = null) {
            const options = { method, headers: { 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' } };
            if (body) {
                options.headers['Content-Type'] = 'application/json';
                options.body = JSON.stringify(body);
            }
            try {
                const res = await fetch(url, options);
                return await res.json();
            } catch (e) {
                logMsg(`API Network Error: ${e.message}`, true);
                return { success: false, error: e.message };
            }
        }

        async function loadConfig() {
            const data = await api('/pbs/api/env');
            const form = document.getElementById('config-form');
            if (data) {
                ['AWS_ENDPOINT', 'AWS_BUCKET', 'AWS_ACCESS_KEY_ID', 'AWS_SECRET_ACCESS_KEY'].forEach(k => {
                    if (form.elements[k]) form.elements[k].value = data[k] || '';
                });
            }
        }

        async function saveConfig(e) {
            e.preventDefault();
            const form = document.getElementById('config-form');
            const body = {
                AWS_ENDPOINT: form.elements.AWS_ENDPOINT.value,
                AWS_BUCKET: form.elements.AWS_BUCKET.value,
                AWS_ACCESS_KEY_ID: form.elements.AWS_ACCESS_KEY_ID.value,
                AWS_SECRET_ACCESS_KEY: form.elements.AWS_SECRET_ACCESS_KEY.value,
                RESTIC_PASSWORD: form.elements.RESTIC_PASSWORD.value
            };
            logMsg("Saving environment configuration...");
            const res = await api('/pbs/api/env/save', 'POST', body);
            if (res.success) logMsg("Configuration saved natively! You can now access Garage parameters dynamically.", false);
        }

        async function loadSnapshots() {
            const tbody = document.querySelector('#snapshots-table tbody');
            tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Querying Garage Server...</td></tr>';
            const res = await api('/pbs/api/restic/list');

            if (!res.success || !res.snapshots) {
                tbody.innerHTML = `<tr><td colspan="4" style="color:#f44336;">Error: ${res.error || 'Repository uninitialized or connection refused.'}</td></tr>`;
                return;
            }

            if (res.snapshots.length === 0) {
                tbody.innerHTML = '<tr><td colspan="4" style="text-align:center;">Empty Datastore. No snapshots exist yet.</td></tr>';
                return;
            }

            const snaps = res.snapshots.reverse();
            let html = '';
            snaps.forEach(s => {
                const date = new Date(s.time).toLocaleString();
                const paths = s.paths ? s.paths.map(p => p.split('/').pop()).join(', ') : 'Unknown';
                html += `<tr>
                    <td style="font-family: monospace;">${s.short_id}</td>
                    <td>${date}</td>
                    <td>${paths}</td>
                    <td>
                        <button class="btn btn-primary" style="padding: 2px 8px;" onclick="restoreSnapshot('${s.short_id}')">Restore</button>
                    </td>
                </tr>`;
            });
            tbody.innerHTML = html;
        }

        async function initDatastore() {
            if (!confirm("Initialize the S3 block datastore?")) return;
            logMsg("Running restic init over S3...");
            const res = await api('/pbs/api/restic/init', 'POST');
            logMsg(res.stdout || res.stderr);
            if (res.stderr && res.stderr.includes("already initialized")) logMsg("Repository is already securely formatted.");
            loadSnapshots();
        }

        async function takeSnapshot() {
            logMsg("Executing atomic SQLite dump & Restic deduplication pass...");
            const res = await api('/pbs/api/restic/snapshot', 'POST');
            logMsg(res.stdout);
            if (res.stderr) logMsg(res.stderr, true);
            loadSnapshots();
        }

        async function restoreSnapshot(id) {
            if (!confirm(`WARNING: Datastore logic will eradicate the current active application and replace it sequentially with Snapshot ${id}. Proceed with Disaster Recovery?`)) return;
            logMsg(`Restoring snapshot [${id}]. Do not close...`);
            const res = await api('/pbs/api/restic/restore', 'POST', { id });
            if (res.success) {
                logMsg(`Restore sequence [${id}] perfectly aligned via PBS Controller!`);
                if (res.output.stdout) logMsg(res.output.stdout);
            } else {
                logMsg(`Restore failed: ${res.error}`, true);
            }
        }

        window.onload = function () {
            loadConfig();
            loadSnapshots();
        };
    </script>
</body>

</html>