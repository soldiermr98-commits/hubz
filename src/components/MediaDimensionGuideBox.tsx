import React from 'react';
import { Info } from 'lucide-react';
import { MEDIA_DIMENSION_GUIDES, MediaSlotKey } from '../services/adminApi';

interface Props {
  slot: MediaSlotKey;
  compact?: boolean;
}

export const MediaDimensionGuideBox: React.FC<Props> = ({ slot, compact = false }) => {
  const guide = MEDIA_DIMENSION_GUIDES[slot];
  return (
    <div
      className={`rounded-lg border border-sky-200 bg-sky-50/70 text-slate-800 ${
        compact ? 'p-2.5 text-[11px] leading-5' : 'p-3 text-xs leading-6'
      }`}
    >
      <div className="flex items-center gap-1.5 font-bold text-sky-950">
        <Info className="w-3.5 h-3.5 text-sky-700 shrink-0" />
        <span>راهنمای ابعاد پیشنهادی: <span dir="ltr" className="font-mono">{guide.recommended_px}</span> پیکسل</span>
      </div>
      <div className="mt-1 text-slate-700 space-y-0.5">
        <div>
          <span className="font-semibold">نسبت استاندارد:</span> {guide.aspect_ratio} ·{' '}
          <span className="font-semibold">حداقل پیشنهادی:</span> <span dir="ltr" className="font-mono">{guide.min_px}</span>
        </div>
        <div>
          <span className="font-semibold">نحوه نمایش در سایت:</span> {guide.fit_mode}
        </div>
        <div className="text-sky-900">{guide.note}</div>
      </div>
    </div>
  );
};
