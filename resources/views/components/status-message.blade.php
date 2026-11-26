@if (session('status'))
    <flux:callout variant="success" icon="check-circle" :heading="session('status')" data-test="status-message" />
@endif
