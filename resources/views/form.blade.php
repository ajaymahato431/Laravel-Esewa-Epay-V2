<form id="esewa-form" action="{{ $endpoint }}" method="POST">
  @foreach($payload as $name => $value)
    <input type="hidden" name="{{ $name }}" value="{{ $value }}">
  @endforeach
  <noscript><button type="submit">Pay with eSewa</button></noscript>
</form>
<script>document.getElementById('esewa-form').submit();</script>
