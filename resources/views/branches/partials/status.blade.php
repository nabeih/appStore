<details class="b-state-control">
    <summary class="b-secondary">{{ $branch->is_active ? 'تعطيل' : 'تفعيل' }}</summary>
    <form method="POST" action="{{ route('branches.status', $branch) }}" data-branch-status data-branch-label="{{ $branch->name }}" data-branch-action="{{ $branch->is_active ? 'تعطيل' : 'تفعيل' }}">
        @csrf @method('PUT')
        <input type="hidden" name="is_active" value="{{ $branch->is_active ? '0' : '1' }}">
        <label for="reason-{{ $branch->id }}">سبب {{ $branch->is_active ? 'التعطيل' : 'التفعيل' }}</label>
        <textarea id="reason-{{ $branch->id }}" name="reason" rows="2" minlength="3" maxlength="1000" required></textarea>
        <button class="b-button" type="submit">تأكيد {{ $branch->is_active ? 'التعطيل' : 'التفعيل' }}</button>
    </form>
</details>
