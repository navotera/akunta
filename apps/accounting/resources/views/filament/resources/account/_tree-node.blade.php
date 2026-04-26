@php
    /** @var array{account: \App\Models\Account, children: array} $node */
    $a = $node['account'];
    $children = $node['children'] ?? [];
    $hasChildren = count($children) > 0;
@endphp

<li>
    <div class="ak-tree-row">
        <span class="ak-tree-toggle {{ $hasChildren ? '' : 'is-leaf' }}"></span>
        <span class="ak-mono text-xs text-gray-600 dark:text-gray-400 w-16 inline-block">{{ $a->code }}</span>
        <span class="{{ $a->is_postable ? '' : 'font-semibold' }}">
            {{ $a->name }}
            @if (! $a->is_postable)
                <span class="ml-1 text-[10px] uppercase tracking-wider text-gray-400">grup</span>
            @endif
        </span>
    </div>

    @if ($hasChildren)
        <ul>
            @foreach ($children as $child)
                @include('filament.resources.account._tree-node', ['node' => $child, 'depth' => ($depth ?? 0) + 1])
            @endforeach
        </ul>
    @endif
</li>
