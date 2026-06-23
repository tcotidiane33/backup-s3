<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserUpload;
use App\Models\SaasStat;
use Illuminate\Support\Facades\Storage;

class DashboardController extends Controller
{
    public function index()
    {
        // Generate a random stat if none exist for today
        if (SaasStat::whereDate('measured_at', today())->count() === 0) {
            SaasStat::create([
                'metric_name' => 'Daily Active Users',
                'metric_value' => rand(100, 1000),
                'measured_at' => today(),
            ]);
        }

        $uploads = UserUpload::latest()->get();
        $stats = SaasStat::latest()->take(10)->get();

        return view('dashboard', compact('uploads', 'stats'));
    }

    public function upload(Request $request)
    {
        $request->validate([
            'file' => 'required|file|max:10240', // 10MB
        ]);

        $file = $request->file('file');
        $path = $file->store('uploads', 'public');

        UserUpload::create([
            'file_name' => $file->getClientOriginalName(),
            'file_path' => $path,
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        return back()->with('success', 'File uploaded successfully.');
    }
}
