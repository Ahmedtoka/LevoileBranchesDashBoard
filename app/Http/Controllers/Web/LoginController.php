<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Models\User;
use Database\Seeders\DemoUsersSeeder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    public function show()
    {
        if (Auth::check()) {
            return redirect()->route('dashboard');
        }

        return view('auth.login');
    }

    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required'],
        ]);

        if (Auth::attempt($credentials, $request->boolean('remember'))) {
            $request->session()->regenerate();

            return redirect()->intended(route('dashboard'));
        }

        return back()->withErrors([
            'email' => 'The provided credentials do not match our records.',
        ])->onlyInput('email');
    }

    /** One-tap demo login. Only when DEMO_LOGIN=true and the email is a seeded demo user. */
    public function quick(Request $request)
    {
        abort_unless(config('app.demo_login'), 404);

        $data = $request->validate(['email' => ['required', 'email']]);

        if (! in_array($data['email'], array_column(DemoUsersSeeder::users(), 'email'), true)) {
            return back()->withErrors(['email' => dt('هذا المستخدم ليس من مستخدمي التجربة.', 'That is not a demo user.')]);
        }

        $user = User::where('email', $data['email'])->first();
        if (! $user) {
            (new DemoUsersSeeder)->upsert([$data['email']]);
            $user = User::where('email', $data['email'])->firstOrFail();
        }

        Auth::login($user);
        $request->session()->regenerate();

        return redirect()->route('dashboard');
    }

    public function logout(Request $request)
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
