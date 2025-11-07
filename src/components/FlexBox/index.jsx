

export function FlexCol({children, className}) {
    return (
        <div className={`flex-col ${className}`}>
            {children}
        </div>
    );
}

export function FlexRow({children, className}) {
   return ( <div className={`flex-row ${className}`}>
        {children}
    </div>
   );
}