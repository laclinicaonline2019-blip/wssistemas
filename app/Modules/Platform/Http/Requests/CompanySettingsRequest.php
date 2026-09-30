<?php

namespace App\Modules\Platform\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Dados que a própria clínica pode editar (não altera plano/status). */
class CompanySettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'trade_name' => ['sometimes', 'string', 'max:200'],
            'email' => ['nullable', 'email:rfc', 'max:190'],
            'phone' => ['nullable', 'string', 'max:20'],
            'settings' => ['sometimes', 'array'],
            'settings.security.require_2fa' => ['sometimes', 'boolean'],
            'settings.print.header_text' => ['nullable', 'string', 'max:300'],
            'settings.print.footer_text' => ['nullable', 'string', 'max:300'],
            'settings.print.thermal_width_mm' => ['sometimes', 'integer', 'in:58,80'],
        ];
    }
}
