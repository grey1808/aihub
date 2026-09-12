<?php

namespace App\Agent\Tools;

/**
 * Инструмент, который модель может вызвать сама.
 *
 * Модель получает описание инструмента, решает, нужен ли он, и присылает
 * аргументы. Мы выполняем и возвращаем результат обратно в диалог.
 */
interface Tool
{
    public function name(): string;

    /** Описание в формате OpenAI function calling. */
    public function definition(): array;

    /**
     * @return array{output:string,sources?:array}
     */
    public function execute(array $arguments): array;
}
