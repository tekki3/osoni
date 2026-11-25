<header class="site-header page-width">
    <a class="brand" href="/" aria-label="{{ $name }} home">
        <img class="brand-logo" src="/assets/images/osoni-logo.png" alt="">
    </a>
    <nav aria-label="Page navigation">
        <a href="/#try-it">Test the form</a>
        <a href="/#build">Get started</a>
        @if(!empty($user))
            <a href="/account">My account</a>
        @else
            <a href="/login">Log in</a>
        @endif
        <a class="health-link" href="/health">Health check <span aria-hidden="true">&rarr;</span></a>
    </nav>
</header>
