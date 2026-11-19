<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function index()
    {
        return view('auth-login');
    }

    public function login($request = null)
    {
        $username = $_POST['username'] ?? ($request->username ?? '');
        $password = $_POST['password'] ?? ($request->password ?? '');

        if ($username === '' || $password === '') {
            return view('auth-login', ['error' => 'Username atau password wajib diisi.']);
        }

        $user = User::where('username', $username)->first();

        if ($user && Hash::check($password, $user->password)) {
            $_SESSION['user_id'] = $user->id ?? 1;
            $_SESSION['username'] = $user->username ?? $username;
            header('Location: /dashboard');
            exit;
        }

        return view('auth-login', ['error' => 'Username atau password salah.']);
    }

    public function logout()
    {
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
        header('Location: /');
        exit;
    }
}
