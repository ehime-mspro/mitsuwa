<?php

namespace Tests\Feature\Admin\Master;

use App\Models\Property;
use Tests\Concerns\CreatesStructureTypeSchema;

/**
 * 構造マスター（Admin\StructureTypeController）。テナント物件の構造（`properties.structure`）は
 * **名前**で持つ（id ではない）。名前を変えたときの物件側は MasterNameReferenceTest が見る。
 */
class StructureTypeMasterTest extends InlineNameMasterTestCase
{
    use CreatesStructureTypeSchema;

    protected function prefix(): string
    {
        return 'structure-types';
    }

    protected function jsFunction(): string
    {
        return 'structureTypeManager';
    }

    protected function table(): string
    {
        return 'structure_types';
    }

    protected function names(): array
    {
        return ['RC造', 'S造', '木造'];
    }

    protected function maxLength(): int
    {
        return 100;
    }

    protected function createMasterSchema(): void
    {
        $this->createStructureTypeSchema();
    }

    private function propertyWith(?string $structure, string $code = 'P-ST-1'): Property
    {
        return Property::create([
            'code' => $code, 'name' => "構造テストビル{$code}", 'property_type' => 'tenant', 'department' => 'tenant',
            'address' => '愛媛県松山市1-1', 'structure' => $structure,
        ]);
    }

    protected function markInUse(int $id, string $name): void
    {
        $this->propertyWith($name);
    }

    protected function inUseMessage(string $name): string
    {
        return "「{$name}」はテナント物件で使用されているため削除できません。";
    }
}
