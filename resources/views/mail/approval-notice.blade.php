{!! $recipientName !!} 様

@foreach($notice->lead as $line)
{!! $line !!}
@endforeach

件名　　　: {!! $context['subject'] !!}
申請者　　: {!! $context['applicant'] !!}（{!! $context['department'] !!}）
決裁No　　: {!! $context['number'] ?? 'まだありません' !!}
必要な対応: {!! $notice->action !!}

▼ 申請を開く
{!! $context['url'] !!}

・このメールは送信専用です。返信しても届きません。
・金額や本文はメールに書いていません。リンクから開いて確かめてください。
