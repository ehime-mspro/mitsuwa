{!! $recipientName !!} 様

あなたの対応を待っている申請が {!! $total !!} 件あります（番が来てから 3 日以上たったもの）。

@foreach($items as $i => $item)
{!! $i + 1 !!}. {!! $item['subject'] !!}（{!! $item['applicant'] !!}・{!! $item['department'] !!}）
   {!! $item['task'] !!} ／ {!! $item['days'] !!} 日待ち
   {!! $item['url'] !!}

@endforeach
@if($rest > 0)
ほか {!! $rest !!} 件はホームで確かめてください。

@endif
▼ 決裁のホーム（ほかの対応待ちも見られます）
{!! $homeUrl !!}

・このメールは、対応されるまで平日の朝 9 時に届きます（土日・祝日・会社の休みの日は届きません）。
・送信専用です。返信しても届きません。
