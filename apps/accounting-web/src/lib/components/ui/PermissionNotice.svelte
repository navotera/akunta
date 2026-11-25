<script lang="ts">
  interface Props {
    message: string;
    testId?: string;
    class?: string;
  }

  let { message, testId = 'permission-notice', class: className = '' }: Props = $props();
</script>

<p
  class="flex items-start gap-2 rounded-md border border-border-default bg-page-bg px-3 py-2 text-sm text-text-muted {className}"
  role="note"
  data-testid={testId}
>
  <svg
    class="mt-0.5 h-4 w-4 shrink-0"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="1.8"
    aria-hidden="true"
  >
    <rect x="5" y="11" width="14" height="9" rx="2" />
    <path d="M8 11V8a4 4 0 0 1 8 0v3" />
  </svg>
  <span>{message}</span>
</p>
