<x-filament-panels::page>
    <div class="space-y-4">
        <x-filament::section>
            <x-slot name="heading">
                Log de Auditoria — Inmutable (§16.13)
            </x-slot>
            <x-slot name="description">
                Registro completo de todos los cambios en configuracion y operaciones sensibles.
                Solo lectura. No editable ni eliminable por ningun usuario.
            </x-slot>

            {{ $this->table }}
        </x-filament::section>
    </div>
</x-filament-panels::page>
