<script lang="ts">
  import { onMount } from 'svelte';

  const ZERO_TEXT = /^(?:Rp\s*)?(?:-?0|\(?0\)?)(?:[,.]0+)?$/;
  const CANDIDATE_SELECTOR = 'td, dd, p, div, span, strong, small, a, button';

  function isZeroText(value: string): boolean {
    return ZERO_TEXT.test(value.replace(/\u00a0/g, ' ').trim());
  }

  function updateZeroValues(root: ParentNode = document) {
    root.querySelectorAll(CANDIDATE_SELECTOR).forEach((element) => {
      if (element.children.length > 0) return;
      element.classList.toggle('ak-zero-value', isZeroText(element.textContent ?? ''));
    });
  }

  onMount(() => {
    updateZeroValues();
    const observer = new MutationObserver(() => updateZeroValues());
    observer.observe(document.body, { childList: true, characterData: true, subtree: true });
    return () => observer.disconnect();
  });
</script>
