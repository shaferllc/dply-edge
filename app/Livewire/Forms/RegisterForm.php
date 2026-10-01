<?php

namespace App\Livewire\Forms;

use Livewire\Form;

class RegisterForm extends Form
{
    public string $name = '';

    public string $email = '';

    public string $password = '';

    public string $password_confirmation = '';

    /** Accepts the Terms, Privacy Policy and AUP (config legal.version). */
    public bool $terms = false;
}
