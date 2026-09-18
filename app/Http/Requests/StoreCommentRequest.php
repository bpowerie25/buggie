<?php

namespace App\Http\Requests;

use App\Support\RichText\TiptapDocument;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreCommentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'array'],
            'is_internal' => ['boolean'],
        ];
    }

    public function after(): array
    {
        return [
            function (Validator $validator) {
                // A tiptap document is never literally empty — an empty editor still
                // posts a doc with one blank paragraph.
                if (TiptapDocument::isEmpty($this->input('body'))) {
                    $validator->errors()->add('body', 'Write something first.');
                }
            },
        ];
    }
}
