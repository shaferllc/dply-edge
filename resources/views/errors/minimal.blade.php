{{-- Any other status: the layout picks copy for $code, or uses the $title / $message given. --}}
@extends('errors.layout', ['code' => $code ?? 500, 'title' => $title ?? null, 'message' => $message ?? null])
