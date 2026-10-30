<?php declare(strict_types=1);

namespace Nova\Versandkosten\Installer;

/**
 * Inhalte der Mail-Vorlagen zur Tour-Benachrichtigung (de/en). Bewusst ohne Strecke und Orte: nur Datum
 * und „in Ihrer Nähe (PLZ-Gebiet …)“. Jede Mail enthält den Abmelde-Link.
 */
final class TourMailTemplates
{
    public const DOI_HTML_DE = <<<'TWIG'
<div style="font-family:arial; font-size:12px;">
    <p>Guten Tag,</p>
    <p>Sie möchten benachrichtigt werden, wenn wir mit unserer eigenen Tour in die Nähe der Postleitzahl {{ novaTour.postcode }} liefern. Bitte bestätigen Sie das mit einem Klick:</p>
    <p><a href="{{ novaTour.confirmUrl }}">Benachrichtigung bestätigen</a></p>
    <p>Der Link gilt {{ novaTour.days }} Tage. Ohne Bestätigung löschen wir Ihre Angaben danach automatisch. Waren Sie das nicht, können Sie diese E-Mail einfach ignorieren.</p>
    <p>Sie können die Benachrichtigung jederzeit über den Link in jeder E-Mail abbestellen.</p>
    <p>Viele Grüße<br>{{ salesChannel.translated.name }}</p>
</div>
TWIG;

    public const DOI_PLAIN_DE = <<<'TWIG'
Guten Tag,

Sie möchten benachrichtigt werden, wenn wir mit unserer eigenen Tour in die Nähe der Postleitzahl {{ novaTour.postcode }} liefern. Bitte bestätigen Sie das über diesen Link:
{{ novaTour.confirmUrl }}

Der Link gilt {{ novaTour.days }} Tage. Ohne Bestätigung löschen wir Ihre Angaben danach automatisch. Waren Sie das nicht, können Sie diese E-Mail einfach ignorieren.

Sie können die Benachrichtigung jederzeit über den Link in jeder E-Mail abbestellen.

Viele Grüße
{{ salesChannel.translated.name }}
TWIG;

    public const DOI_HTML_EN = <<<'TWIG'
<div style="font-family:arial; font-size:12px;">
    <p>Hello,</p>
    <p>you would like to be notified when our own delivery tour comes near the postcode {{ novaTour.postcode }}. Please confirm with one click:</p>
    <p><a href="{{ novaTour.confirmUrl }}">Confirm notification</a></p>
    <p>The link is valid for {{ novaTour.days }} days. Without confirmation we delete your details automatically afterwards. If this was not you, simply ignore this e-mail.</p>
    <p>You can unsubscribe at any time using the link in every e-mail.</p>
    <p>Kind regards<br>{{ salesChannel.translated.name }}</p>
</div>
TWIG;

    public const DOI_PLAIN_EN = <<<'TWIG'
Hello,

you would like to be notified when our own delivery tour comes near the postcode {{ novaTour.postcode }}. Please confirm using this link:
{{ novaTour.confirmUrl }}

The link is valid for {{ novaTour.days }} days. Without confirmation we delete your details automatically afterwards. If this was not you, simply ignore this e-mail.

You can unsubscribe at any time using the link in every e-mail.

Kind regards
{{ salesChannel.translated.name }}
TWIG;

    public const NOTIFY_HTML_DE = <<<'TWIG'
<div style="font-family:arial; font-size:12px;">
    <p>Guten Tag,</p>
    <p>am <strong>{{ novaTour.date }}</strong> liefern wir mit unserer eigenen Tour in Ihrer Nähe ({{ novaTour.area }}).{% if novaTour.trailer %} Diesmal sind wir mit Hänger unterwegs und nehmen auch Speditionsware mit.{% endif %}</p>
    <p>Bestellen Sie bis {{ novaTour.deadline }} und wählen Sie in der Kasse die Versandart „Lieferung mit unserer Tour“ – ob Ihre Lieferadresse passt und was die Lieferung kostet, sehen Sie dort.</p>
    <p><a href="{{ novaTour.shopUrl }}">Zum Shop</a></p>
    <p style="color:#666;">Sie erhalten diese E-Mail, weil Sie die Tour-Benachrichtigung bestellt haben.
        <a href="{{ novaTour.renewUrl }}">Weiter benachrichtigen</a> (verlängert die Speicherdauer) ·
        <a href="{{ novaTour.unsubscribeUrl }}">Abmelden</a></p>
    <p>Viele Grüße<br>{{ salesChannel.translated.name }}</p>
</div>
TWIG;

    public const NOTIFY_PLAIN_DE = <<<'TWIG'
Guten Tag,

am {{ novaTour.date }} liefern wir mit unserer eigenen Tour in Ihrer Nähe ({{ novaTour.area }}).{% if novaTour.trailer %} Diesmal sind wir mit Hänger unterwegs und nehmen auch Speditionsware mit.{% endif %}

Bestellen Sie bis {{ novaTour.deadline }} und wählen Sie in der Kasse die Versandart „Lieferung mit unserer Tour“ – ob Ihre Lieferadresse passt und was die Lieferung kostet, sehen Sie dort.

Zum Shop: {{ novaTour.shopUrl }}

Sie erhalten diese E-Mail, weil Sie die Tour-Benachrichtigung bestellt haben.
Weiter benachrichtigen (verlängert die Speicherdauer): {{ novaTour.renewUrl }}
Abmelden: {{ novaTour.unsubscribeUrl }}

Viele Grüße
{{ salesChannel.translated.name }}
TWIG;

    public const NOTIFY_HTML_EN = <<<'TWIG'
<div style="font-family:arial; font-size:12px;">
    <p>Hello,</p>
    <p>on <strong>{{ novaTour.date }}</strong> our own delivery tour comes near you ({{ novaTour.area }}).{% if novaTour.trailer %} This time we travel with a trailer and can also take freight goods.{% endif %}</p>
    <p>Order by {{ novaTour.deadline }} and choose the shipping method “Delivery with our own tour” at checkout – there you can see whether your delivery address fits and what the delivery costs.</p>
    <p><a href="{{ novaTour.shopUrl }}">Go to the shop</a></p>
    <p style="color:#666;">You receive this e-mail because you subscribed to the tour notification.
        <a href="{{ novaTour.renewUrl }}">Keep notifying me</a> (extends the storage period) ·
        <a href="{{ novaTour.unsubscribeUrl }}">Unsubscribe</a></p>
    <p>Kind regards<br>{{ salesChannel.translated.name }}</p>
</div>
TWIG;

    public const NOTIFY_PLAIN_EN = <<<'TWIG'
Hello,

on {{ novaTour.date }} our own delivery tour comes near you ({{ novaTour.area }}).{% if novaTour.trailer %} This time we travel with a trailer and can also take freight goods.{% endif %}

Order by {{ novaTour.deadline }} and choose the shipping method “Delivery with our own tour” at checkout – there you can see whether your delivery address fits and what the delivery costs.

Shop: {{ novaTour.shopUrl }}

You receive this e-mail because you subscribed to the tour notification.
Keep notifying me (extends the storage period): {{ novaTour.renewUrl }}
Unsubscribe: {{ novaTour.unsubscribeUrl }}

Kind regards
{{ salesChannel.translated.name }}
TWIG;
}
