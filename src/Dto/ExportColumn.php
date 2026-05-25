<?php

namespace Dimer47\BackpackServerExport\Dto;

class ExportColumn
{
    public function __construct(
        public readonly string $name,
        public readonly string $label,
        public readonly string $type = 'text',
        public readonly ?string $entity = null,
        public readonly ?string $attribute = null,
        public readonly ?\Closure $valueResolver = null,
    ) {}

    public static function make(string $name): static
    {
        return new static(name: $name, label: $name);
    }

    public function withLabel(string $label): static
    {
        return new static(
            name: $this->name,
            label: $label,
            type: $this->type,
            entity: $this->entity,
            attribute: $this->attribute,
            valueResolver: $this->valueResolver,
        );
    }

    public function withType(string $type): static
    {
        return new static(
            name: $this->name,
            label: $this->label,
            type: $type,
            entity: $this->entity,
            attribute: $this->attribute,
            valueResolver: $this->valueResolver,
        );
    }

    public function withRelation(string $entity, string $attribute): static
    {
        return new static(
            name: $this->name,
            label: $this->label,
            type: 'relationship',
            entity: $entity,
            attribute: $attribute,
            valueResolver: $this->valueResolver,
        );
    }

    public function withResolver(\Closure $resolver): static
    {
        return new static(
            name: $this->name,
            label: $this->label,
            type: $this->type,
            entity: $this->entity,
            attribute: $this->attribute,
            valueResolver: $resolver,
        );
    }
}
