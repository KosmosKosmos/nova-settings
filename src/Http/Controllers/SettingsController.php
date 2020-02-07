<?php

namespace Epigra\NovaSettings\Http\Controllers;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Routing\Controller;
use Illuminate\Http\Request;
use Epigra\NovaSettings\NovaSettingsTool;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Laravel\Nova\Contracts\Resolvable;
use Laravel\Nova\Http\Requests\NovaRequest;

class SettingsController extends Controller
{
    public function get(Request $request)
    {
        $fields = collect(NovaSettingsTool::getSettingsFields());

        $fields->whereInstanceOf(Resolvable::class)->each(function (&$field) {
            if (!empty($field->attribute)) {
                $key = $field->attribute;
                $value = setting($key);
                if (strpos($key, 'encrypted_') === 0) {
                    $key = substr_replace($key, '', 0, 10);
                    try {
                        $decrypted = Crypt::decrypt(setting($key));
                        $value = substr($decrypted, 0, 1).'***'.substr($decrypted, -1, 1);
                    } catch (DecryptException $e) {
                        $value = setting($key);
                    }
                }
                $field->resolve([$field->attribute => $value]);
            }
        });

        return $fields;
    }

    public function save(NovaRequest $request)
    {
        $fields = collect(NovaSettingsTool::getSettingsFields());

        $fields->whereInstanceOf(Resolvable::class)->each(function ($field) use ($request) {
            if (empty($field->attribute)) return;

            $tempResource =  new \stdClass;
            $field->fill($request, $tempResource);

            if (property_exists($tempResource, $field->attribute)) {
                $key = $field->attribute;
                $value = $tempResource->{$field->attribute};

                if (strpos($key, 'encrypted_') === 0) {
                    $key = substr_replace($key, '', 0, 10);
                    try {
                        Crypt::decrypt($value);
                    } catch (DecryptException $e) {
                        if (strpos($value, '***') == 1 && strlen($value) == 5) {
                            $value = setting($key);
                        } else {
                            $value = Crypt::encrypt($value);
                        }
                    }
                }
                setting([$key =>  $value]);
            }

        });

        setting()->save();

        if (config('nova-settings.restart_queue', false)) {
            Artisan::call('queue:restart');
        }

        return response('', 204);
    }
}
