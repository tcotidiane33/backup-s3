<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>SaaS Backup Dashboard</title>
    <style>
        :root {
            --bg-color: #0f172a;
            --glass-bg: rgba(30, 41, 59, 0.7);
            --glass-border: rgba(255, 255, 255, 0.1);
            --primary: #3b82f6;
            --primary-hover: #2563eb;
            --text-main: #f8fafc;
            --text-muted: #94a3b8;
        }

        body,
        html {
            margin: 0;
            padding: 0;
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif, "Apple Color Emoji", "Segoe UI Emoji", "Segoe UI Symbol";
            background-color: var(--bg-color);
            color: var(--text-main);
            min-height: 100vh;
        }

        .background-blob {
            position: fixed;
            top: -100px;
            left: -100px;
            width: 400px;
            height: 400px;
            background: radial-gradient(circle, rgba(59, 130, 246, 0.3) 0%, rgba(15, 23, 42, 0) 70%);
            border-radius: 50%;
            z-index: -1;
            filter: blur(40px);
        }

        .background-blob.right {
            top: auto;
            bottom: -100px;
            left: auto;
            right: -100px;
            background: radial-gradient(circle, rgba(139, 92, 246, 0.3) 0%, rgba(15, 23, 42, 0) 70%);
        }

        .container {
            max-width: 1000px;
            margin: 0 auto;
            padding: 2rem;
        }

        header {
            text-align: center;
            margin-bottom: 3rem;
            animation: fadeInDown 0.8s ease-out;
        }

        h1 {
            font-weight: 800;
            font-size: 2.5rem;
            margin-bottom: 0.5rem;
            background: linear-gradient(to right, #60a5fa, #a78bfa);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
        }

        .glass-panel {
            background: var(--glass-bg);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            border: 1px solid var(--glass-border);
            border-radius: 16px;
            padding: 2rem;
            margin-bottom: 2rem;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
            animation: fadeInUp 0.8s ease-out backwards;
        }

        .glass-panel:nth-child(2) {
            animation-delay: 0.1s;
        }

        .glass-panel:nth-child(3) {
            animation-delay: 0.2s;
        }

        h2 {
            font-size: 1.5rem;
            margin-top: 0;
            margin-bottom: 1.5rem;
            color: var(--text-main);
            border-bottom: 1px solid var(--glass-border);
            padding-bottom: 0.5rem;
        }

        .upload-area {
            border: 2px dashed var(--glass-border);
            border-radius: 12px;
            padding: 2rem;
            text-align: center;
            transition: all 0.3s ease;
            cursor: pointer;
        }

        .upload-area:hover {
            border-color: var(--primary);
            background: rgba(59, 130, 246, 0.05);
        }

        .btn {
            background-color: var(--primary);
            color: white;
            border: none;
            padding: 0.75rem 1.5rem;
            border-radius: 8px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-size: 1rem;
            margin-top: 1rem;
        }

        .btn:hover {
            background-color: var(--primary-hover);
            transform: translateY(-2px);
            box-shadow: 0 4px 12px rgba(59, 130, 246, 0.3);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th,
        td {
            padding: 1rem;
            text-align: left;
            border-bottom: 1px solid var(--glass-border);
        }

        th {
            color: var(--text-muted);
            font-weight: 500;
            text-transform: uppercase;
            font-size: 0.875rem;
            letter-spacing: 0.05em;
        }

        tr {
            transition: background-color 0.2s ease;
        }

        tr:hover {
            background-color: rgba(255, 255, 255, 0.05);
        }

        .file-link {
            color: #60a5fa;
            text-decoration: none;
        }

        .file-link:hover {
            text-decoration: underline;
        }

        .alert {
            background-color: rgba(16, 185, 129, 0.1);
            color: #10b981;
            border: 1px solid rgba(16, 185, 129, 0.2);
            padding: 1rem;
            border-radius: 8px;
            margin-bottom: 2rem;
            animation: fadeIn 0.5s ease-out;
        }

        @keyframes fadeInDown {
            from {
                opacity: 0;
                transform: translateY(-20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }

            to {
                opacity: 1;
                transform: translateY(0);
            }
        }

        @keyframes fadeIn {
            from {
                opacity: 0;
            }

            to {
                opacity: 1;
            }
        }

        .nav-bar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 0.75rem 2rem;
            background: rgba(30, 41, 59, 0.8);
            backdrop-filter: blur(12px);
            border-bottom: 1px solid var(--glass-border);
            position: sticky;
            top: 0;
            z-index: 100;
        }

        .nav-bar a {
            color: var(--text-muted);
            text-decoration: none;
            font-size: 0.875rem;
            padding: 0.5rem 1rem;
            border-radius: 6px;
            transition: all 0.3s ease;
        }

        .nav-bar a:hover {
            color: var(--text-main);
            background: rgba(255, 255, 255, 0.1);
        }

        .nav-bar a.active {
            color: #60a5fa;
            background: rgba(59, 130, 246, 0.1);
        }

        .nav-bar .brand-text {
            font-weight: 700;
            font-size: 1rem;
            color: var(--text-main);
        }
    </style>
</head>

<body>
    <div class="background-blob"></div>
    <div class="background-blob right"></div>

    <div class="nav-bar">
        <span class="brand-text">DocuVault SaaS</span>
        <div>
            <a href="/" class="active">📊 Dashboard</a>
            <a href="/bs">🛡️ Kondro Backup</a>
        </div>
    </div>

    <div class="container">
        <header>
            <h1>SaaS Datacenter</h1>
            <p style="color: var(--text-muted);">Manage your files and monitor application statistics.</p>
        </header>

        @if(session('success'))
            <div class="alert">
                {{ session('success') }}
            </div>
        @endif
        @if($errors->any())
            <div class="alert"
                style="background-color: rgba(239, 68, 68, 0.1); color: #ef4444; border-color: rgba(239, 68, 68, 0.2);">
                @foreach ($errors->all() as $error)
                    <div>{{ $error }}</div>
                @endforeach
            </div>
        @endif

        <div class="glass-panel">
            <h2>Upload a File</h2>
            <form action="{{ route('upload') }}" method="POST" enctype="multipart/form-data">
                @csrf
                <div class="upload-area" onclick="document.getElementById('file-input').click()">
                    <p>Click to select a file to upload</p>
                    <p style="font-size: 0.875rem; color: var(--text-muted);">Max 10MB. Files are synced to Garage
                        backups.</p>
                </div>
                <input type="file" name="file" id="file-input" style="display: none;"
                    onchange="document.getElementById('upload-btn').style.display='inline-block'; document.getElementById('selected-file').innerText=this.files[0].name;"
                    required>
                <div style="margin-top: 1rem; display: flex; align-items: center; justify-content: space-between;">
                    <span id="selected-file" style="color: #60a5fa; font-weight: 500;"></span>
                    <button type="submit" class="btn" id="upload-btn" style="display: none; margin-top: 0;">Upload
                        File</button>
                </div>
            </form>
        </div>

        <div class="glass-panel">
            <h2>User Uploads</h2>
            @if($uploads->count() > 0)
                <table>
                    <thead>
                        <tr>
                            <th>File Name</th>
                            <th>Size</th>
                            <th>MIME Type</th>
                            <th>Uploaded At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($uploads as $upload)
                            <tr>
                                <td><a href="{{ asset('storage/' . $upload->file_path) }}" class="file-link"
                                        target="_blank">{{ $upload->file_name }}</a></td>
                                <td>{{ number_format($upload->size / 1024, 2) }} KB</td>
                                <td>{{ $upload->mime_type }}</td>
                                <td>{{ $upload->created_at->format('M d, Y H:i') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="color: var(--text-muted);">No files uploaded yet.</p>
            @endif
        </div>

        <div class="glass-panel">
            <h2>SaaS Statistics</h2>
            @if($stats->count() > 0)
                <table>
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>Value</th>
                            <th>Measured At</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($stats as $stat)
                            <tr>
                                <td>{{ $stat->metric_name }}</td>
                                <td style="font-weight: 600; color: #a78bfa;">{{ number_format($stat->metric_value) }}</td>
                                <td>{{ \Carbon\Carbon::parse($stat->measured_at)->format('M d, Y') }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            @else
                <p style="color: var(--text-muted);">No statistics recorded yet.</p>
            @endif
        </div>
    </div>
</body>

</html>