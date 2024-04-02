<?php

/*
 * This file is part of james.xue/sms-bombing.
 *
 * (c) xiaoxuan6 <1527736751@qq.com>
 *
 * This source file is subject to the MIT license that is bundled
 * with this source code in the file LICENSE.
 *
 */

namespace App\Commands;

use Exception;
use App\Services\DeepLTranslator;
use TitasGailius\Terminal\Terminal;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;
use Illuminate\Http\Client\RequestException;

use function Laravel\Prompts\text;

class ExplainCommand extends Command
{
    /**
     * The signature of the command.
     *
     * @var string
     */
    protected $signature = 'explain
        {code? : The code to explain}
        {--C|is-use-clipboard : Use clipboard to get the code to explain}
    ';

    /**
     * The description of the command.
     *
     * @var string
     */
    protected $description = 'What does it mean to explain the code.';

    /**
     * Execute the console command.
     * @return int
     * @throws RequestException
     */
    public function handle(): int
    {
        return collect()
            ->tap(
                function () use (&$code): void {
                    $code = str(
                        value(function () {
                            if ($this->option('is-use-clipboard')) {
                                return str(
                                    match (PHP_OS_FAMILY) {
                                        'Windows' => Terminal::builder()->run('powershell -sta "add-type -as System.Windows.Forms; [windows.forms.clipboard]::GetText()"')->output(),
                                        'Linux' => Terminal::builder()->run('xclip -out -selection primary')->output(),
                                        'Darwin' => Terminal::builder()->run('pbpaste')->output(),
                                        default => '',
                                    }
                                )->trim()->whenEmpty($this->textCallable());
                            }

                            return $this->argument('code') ?? $this->textCallable()();
                        })
                    )->trim()->toString();
                }
            )
            ->tap(function () use ($code, &$result): void {
                $this->info('code:');
                $this->warn(sprintf("%s" . PHP_EOL, $code));

                $this->task(
                    '   1. code <fg=yellow>explain</>',
                    function () use ($code, &$result): bool {
                        $response = Http::timeout(10)
                            ->withoutVerifying()
                            ->withHeader('Content-Type', 'application/json')
                            ->retry(3, 1000, throw: false)
                            ->post('https://whatdoesthiscodedo.com/api/stream-text', [
                                'code' => str($code)->replace("\r\n", "\n")
                            ])
                            ->throw(fn ($response, $e) => $this->error($e->getMessage()));

                        if ($response->successful()) {
                            $body = str($response->body())->trim()->toString();

                            $arr = preg_split('/\R/', $body);
                            $result = current($arr);

                            return true;
                        }

                        $this->error(sprintf("explain: \n%s", $response->reason()));
                        $this->output->newLine();

                        return false;
                    }
                );
                $this->output->newLine();
            })
            ->tap(function () use ($result): void {
                if (strlen($result) > 0) {
                    $response = $this->task(
                        '   2. code explain <fg=yellow>result translate</>',
                        function () use ($result, &$message): bool {
                            try {
                                $collect = collect(DeepLTranslator::withoutVerifying()->en2zh($result));

                                if ($collect->get('code') == 1000) {
                                    $message = sprintf("explain: \n%s", $collect->get('data'));

                                    return true;
                                }
                                $message = sprintf("explain: \n%s", $collect->get('message'));

                                return false;
                            } catch (Exception $exception) {
                                $message = sprintf("explain: \n%s", $exception->getMessage());

                                return false;
                            }
                        }
                    );
                    $this->output->newLine();
                    $response == true ? $this->info($message) : $this->error($message);
                } else {
                    $this->error('explain: result is empty');
                }
            })
            ->pipe(static fn (): int => self::SUCCESS);
    }

    public function textCallable(): callable
    {
        return fn (): string => text(
            'What does it mean to explain the code?',
            placeholder: 'Enter code here',
            required: true,
            validate: fn ($value): ?string => $value ? null : 'Code is required'
        );
    }
}
